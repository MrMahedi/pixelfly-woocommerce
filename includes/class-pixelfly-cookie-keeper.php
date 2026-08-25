<?php

/**
 * PixelFly Cookie Keeper
 *
 * Opt-in. Default off — existing stores are unchanged until both this plugin
 * toggle AND the dashboard Cookie Keeper toggle are enabled.
 *
 * 1. Confirms Worker cookie_keeper.enabled before writing any cookies.
 * 2. Sets _pf_mid from the shop (PHP Set-Cookie, same IP as the page).
 * 3. Stores _fbp/_fbc/_ga against that ID on PixelFly /ck.
 * 4. If Safari deleted _fbp, restores via PHP setcookie on the shop host.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Cookie_Keeper
{
    const MASTER_COOKIE = '_pf_mid';
    const MAX_AGE_DAYS = 400;

    /**
     * @var self|null
     */
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (!self::is_enabled()) {
            return;
        }

        add_action('init', [$this, 'bootstrap'], 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_store_script'], 30);
    }

    public static function is_enabled()
    {
        if (!get_option('pixelfly_cookie_keeper_enabled', false)) {
            return false;
        }

        if (!get_option('pixelfly_api_key', '')) {
            return false;
        }

        return true;
    }

    private function should_run()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (is_user_logged_in()) {
            $excluded = (array) get_option('pixelfly_excluded_roles', []);
            $user = wp_get_current_user();
            if (!empty($excluded) && array_intersect($excluded, (array) $user->roles)) {
                return false;
            }
        }

        return $this->has_ad_consent();
    }

    private function has_ad_consent()
    {
        if (!get_option('pixelfly_consent_enabled', false)) {
            return true;
        }

        if (!class_exists('PixelFly_Consent')) {
            return false;
        }

        $consent = PixelFly_Consent::get_instance();
        return $consent->has_consent('ad_storage');
    }

    public function bootstrap()
    {
        if (!$this->should_run() || headers_sent()) {
            return;
        }

        $existing = '';
        if (!empty($_COOKIE[self::MASTER_COOKIE])) {
            $candidate = sanitize_text_field(wp_unslash($_COOKIE[self::MASTER_COOKIE]));
            if (preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $candidate)) {
                $existing = $candidate;
            }
        }

        // Fast path: master already present and marketing cookie still there — no network.
        if ($existing !== '' && !empty($_COOKIE['_fbp'])) {
            return;
        }

        $mid = $existing !== '' ? $existing : $this->generate_master_id();
        $api = new PixelFly_API();
        $result = $api->cookie_keeper_request('restore', $mid, []);

        // Dashboard toggle off → do not set _pf_mid or marketing cookies.
        if (!is_array($result) || empty($result['enabled'])) {
            return;
        }

        if ($existing === '') {
            $this->set_shop_cookie(self::MASTER_COOKIE, $mid);
            $_COOKIE[self::MASTER_COOKIE] = $mid;
        }

        if (!empty($_COOKIE['_fbp']) || empty($result['cookies']) || !is_array($result['cookies'])) {
            return;
        }

        foreach ($result['cookies'] as $name => $value) {
            if (!$this->is_allowed_cookie_name($name)) {
                continue;
            }

            $clean = sanitize_text_field($value);
            if ($clean === '') {
                continue;
            }

            if ($name === '_fbc' && !empty($_GET['fbclid'])) {
                continue;
            }

            $this->set_shop_cookie($name, $clean);
            $_COOKIE[$name] = $clean;
        }
    }

    private function generate_master_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return str_replace('-', '', wp_generate_uuid4());
        }

        return bin2hex(random_bytes(16));
    }

    private function is_allowed_cookie_name($name)
    {
        if (in_array($name, ['_fbp', '_fbc', '_ga'], true)) {
            return true;
        }

        return (bool) preg_match('/^_ga_[A-Z0-9]{4,20}$/', $name);
    }

    /**
     * Host-only cookie from the shop origin — same IP as the HTML page.
     */
    private function set_shop_cookie($name, $value)
    {
        $expire = time() + (self::MAX_AGE_DAYS * DAY_IN_SECONDS);

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            return;
        }

        setcookie($name, $value, $expire, '/', '', is_ssl(), false);
    }

    public function enqueue_store_script()
    {
        if (!$this->should_run()) {
            return;
        }

        // Only load store JS when master cookie already exists (dashboard confirmed enabled).
        if (empty($_COOKIE[self::MASTER_COOKIE])) {
            return;
        }

        $api_key = get_option('pixelfly_api_key', '');
        $endpoint = get_option('pixelfly_endpoint', 'https://track.pixelfly.io/e');
        $ck_url = $this->keeper_url($endpoint);

        wp_register_script('pixelfly-cookie-keeper', false, [], PIXELFLY_VERSION, true);
        wp_enqueue_script('pixelfly-cookie-keeper');
        wp_add_inline_script(
            'pixelfly-cookie-keeper',
            'window.pixelFlyCookieKeeper=' . wp_json_encode([
                'url' => $ck_url,
                'key' => $api_key,
                'midCookie' => self::MASTER_COOKIE,
            ]) . ';' . $this->store_js(),
            'after'
        );
    }

    private function keeper_url($endpoint)
    {
        $replaced = preg_replace('#/e/?(\?.*)?$#', '/ck$1', $endpoint);
        if (is_string($replaced) && $replaced !== $endpoint) {
            return $replaced;
        }

        return rtrim($endpoint, '/') . '/ck';
    }

    private function store_js()
    {
        return <<<'JS'
(function () {
  var cfg = window.pixelFlyCookieKeeper;
  if (!cfg || !cfg.url || !cfg.key) return;

  function readCookie(name) {
    var parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length < 2) return '';
    return parts.pop().split(';').shift();
  }

  function collect() {
    var cookies = {};
    ['_fbp', '_fbc', '_ga'].forEach(function (name) {
      var v = readCookie(name);
      if (v) cookies[name] = v;
    });
    document.cookie.split(';').forEach(function (pair) {
      var p = pair.trim().split('=');
      if (p[0] && p[0].indexOf('_ga_') === 0 && p[1]) {
        cookies[p[0]] = p.slice(1).join('=');
      }
    });
    return cookies;
  }

  function send() {
    var mid = readCookie(cfg.midCookie);
    var cookies = collect();
    if (!mid || Object.keys(cookies).length === 0) return;
    try {
      fetch(cfg.url, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-PF-Key': cfg.key
        },
        body: JSON.stringify({ action: 'store', mid: mid, cookies: cookies }),
        keepalive: true
      }).catch(function () {});
    } catch (e) {}
  }

  function schedule() {
    setTimeout(send, 1800);
  }

  if (document.readyState === 'complete') schedule();
  else window.addEventListener('load', schedule);
})();
JS;
    }
}
