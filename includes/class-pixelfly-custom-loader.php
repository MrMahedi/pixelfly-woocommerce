<?php
/**
 * PixelFly Custom Loader
 *
 * Loads GTM, GA4, and Meta Pixel scripts through PixelFly's first-party domain
 * to bypass ad blockers and improve tracking accuracy.
 *
 * @package PixelFly_WooCommerce
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Custom_Loader {

    /**
     * Singleton instance.
     */
    private static $instance = null;

    /**
     * Custom domain for script loading.
     */
    private $custom_domain = '';

    /**
     * GTM Container ID.
     */
    private $gtm_id = '';

    /**
     * Get singleton instance.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->custom_domain = get_option('pixelfly_custom_loader_domain', '');
        $this->gtm_id = get_option('pixelfly_gtm_container_id', '');

        if (!$this->is_enabled()) {
            return;
        }

        // Filter the GTM domain (this hook already exists in the plugin)
        add_filter('pixelfly_gtm_domain', array($this, 'filter_gtm_domain'));

        // Override GTM script injection if Custom Loader is enabled
        add_action('wp_head', array($this, 'inject_custom_loader_script'), 1);

        // Add filter to check if we should use standard GTM injection
        add_filter('pixelfly_use_standard_gtm', array($this, 'disable_standard_gtm'));
    }

    /**
     * Check if Custom Loader is enabled.
     */
    public function is_enabled() {
        $enabled = get_option('pixelfly_custom_loader_enabled', false);
        return $enabled && !empty($this->custom_domain) && !empty($this->gtm_id);
    }

    /**
     * Get the custom domain.
     */
    public function get_custom_domain() {
        return $this->custom_domain;
    }

    /**
     * Filter the GTM domain for existing code.
     */
    public function filter_gtm_domain($domain) {
        if ($this->is_enabled()) {
            return $this->custom_domain;
        }
        return $domain;
    }

    /**
     * Disable standard GTM injection when Custom Loader is active.
     */
    public function disable_standard_gtm($use_standard) {
        if ($this->is_enabled()) {
            return false;
        }
        return $use_standard;
    }

    /**
     * Get the stealth GTM Container ID (without GTM- prefix).
     */
    private function get_stealth_id() {
        $id = $this->gtm_id;
        // Strip GTM- prefix for stealth mode
        if (strpos($id, 'GTM-') === 0) {
            $id = substr($id, 4);
        }
        return $id;
    }

    /**
     * Inject Custom Loader script (stealth GTM).
     */
    public function inject_custom_loader_script() {
        if (!$this->is_enabled()) {
            return;
        }

        $domain = esc_attr($this->custom_domain);
        $stealth_id = esc_js($this->get_stealth_id());

        // Check consent if consent mode is enabled
        $consent_class = class_exists('PixelFly_Consent') ? PixelFly_Consent::get_instance() : null;
        $should_load = true;

        if ($consent_class && $consent_class->is_consent_enabled()) {
            // Custom Loader will still load, but GTM will respect consent signals
            // The consent defaults are already set before this script
        }

        ?>
<!-- PixelFly Custom Loader - Stealth GTM -->
<script>
(function(a,b,c,d,e){
    a[d]=a[d]||[];
    a[d].push({'pf.init': new Date().getTime(), event:'pf.run'});
    var f=b.getElementsByTagName(c)[0],
        g=b.createElement(c),
        h=d!='pfData'?'&d='+d:'';
    g.async=true;
    g.src='https://<?php echo $domain; ?>/pf.js?c='+e+h;
    f.parentNode.insertBefore(g,f);
})(window,document,'script','pfData','<?php echo $stealth_id; ?>');
</script>
<!-- End PixelFly Custom Loader -->
        <?php
    }

    /**
     * Get the script URL for Custom Loader.
     */
    public function get_script_url() {
        if (!$this->is_enabled()) {
            return '';
        }

        return sprintf(
            'https://%s/pf.js?c=%s',
            $this->custom_domain,
            $this->get_stealth_id()
        );
    }

    /**
     * Get collect endpoint URL for GA4.
     */
    public function get_ga4_collect_url() {
        if (!$this->is_enabled()) {
            return 'https://www.google-analytics.com/g/collect';
        }

        return sprintf('https://%s/a/c', $this->custom_domain);
    }

    /**
     * Get collect endpoint URL for Meta Pixel.
     */
    public function get_meta_pixel_url() {
        if (!$this->is_enabled()) {
            return 'https://www.facebook.com/tr';
        }

        return sprintf('https://%s/s/p', $this->custom_domain);
    }

    /**
     * Get the noscript iframe for GTM.
     */
    public function get_noscript_iframe() {
        if (!$this->is_enabled()) {
            return '';
        }

        // For noscript, we use the standard GTM URL since it's a fallback
        // and ad blockers typically only block JavaScript
        return sprintf(
            '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
            esc_attr($this->gtm_id)
        );
    }

    /**
     * Validate custom domain format.
     */
    public static function validate_domain($domain) {
        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Basic validation
        if (empty($domain)) {
            return new WP_Error('empty_domain', __('Domain cannot be empty.', 'pixelfly-woocommerce'));
        }

        // Check for valid domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
            return new WP_Error('invalid_domain', __('Please enter a valid domain (e.g., t.yourstore.com).', 'pixelfly-woocommerce'));
        }

        return $domain;
    }

    /**
     * Test if the custom domain is working.
     */
    public static function test_custom_domain($domain, $gtm_id = 'GTM-TEST123') {
        $stealth_id = strpos($gtm_id, 'GTM-') === 0 ? substr($gtm_id, 4) : $gtm_id;

        $test_url = sprintf('https://%s/pf.js?c=%s', $domain, $stealth_id);

        $response = wp_remote_head($test_url, array(
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 200) {
            return array(
                'success' => true,
                'message' => __('Custom domain is working correctly!', 'pixelfly-woocommerce'),
            );
        }

        return array(
            'success' => false,
            'message' => sprintf(__('Domain returned status %d. Make sure the custom domain is configured in PixelFly dashboard.', 'pixelfly-woocommerce'), $status),
        );
    }
}
