<?php
/**
 * PixelFly Consent Mode V2
 *
 * Handles GDPR-compliant consent management with Google Consent Mode V2.
 * Provides a customizable consent banner and manages consent signals.
 *
 * @package PixelFly_WooCommerce
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Consent {

    /**
     * Singleton instance.
     */
    private static $instance = null;

    /**
     * Consent cookie name.
     */
    const CONSENT_COOKIE = 'pixelfly_consent';

    /**
     * Consent cookie duration (365 days).
     */
    const CONSENT_DURATION = 365;

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
        if (!$this->is_consent_enabled()) {
            return;
        }

        // Inject consent mode defaults BEFORE GTM loads
        add_action('wp_head', array($this, 'inject_consent_defaults'), 0);

        // Show consent banner
        add_action('wp_footer', array($this, 'render_consent_banner'));

        // Enqueue consent scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX handlers for consent
        add_action('wp_ajax_pixelfly_save_consent', array($this, 'ajax_save_consent'));
        add_action('wp_ajax_nopriv_pixelfly_save_consent', array($this, 'ajax_save_consent'));
    }

    /**
     * Check if consent mode is enabled.
     */
    public function is_consent_enabled() {
        return get_option('pixelfly_consent_enabled', false);
    }

    /**
     * Get consent mode (banner, integration, or none).
     */
    public function get_consent_mode() {
        return get_option('pixelfly_consent_mode', 'banner');
    }

    /**
     * Get current consent state.
     */
    public function get_consent_state() {
        if (isset($_COOKIE[self::CONSENT_COOKIE])) {
            $consent = json_decode(stripslashes($_COOKIE[self::CONSENT_COOKIE]), true);
            if (is_array($consent)) {
                return $consent;
            }
        }

        // Default: no consent given (denied)
        return array(
            'analytics_storage' => 'denied',
            'ad_storage' => 'denied',
            'ad_user_data' => 'denied',
            'ad_personalization' => 'denied',
            'functionality_storage' => 'denied',
            'personalization_storage' => 'denied',
            'security_storage' => 'granted', // Always granted for security
        );
    }

    /**
     * Check if user has given consent.
     */
    public function has_consent($type = 'analytics_storage') {
        $consent = $this->get_consent_state();
        return isset($consent[$type]) && $consent[$type] === 'granted';
    }

    /**
     * Check if consent banner should be shown.
     */
    public function should_show_banner() {
        // Don't show if consent is disabled
        if (!$this->is_consent_enabled()) {
            return false;
        }

        // Don't show in admin
        if (is_admin()) {
            return false;
        }

        // Don't show if already has consent cookie
        if (isset($_COOKIE[self::CONSENT_COOKIE])) {
            return false;
        }

        // Check region targeting
        $region_setting = get_option('pixelfly_consent_region', 'all');
        if ($region_setting === 'gdpr' && !$this->is_gdpr_country()) {
            return false;
        }

        return true;
    }

    /**
     * Check if visitor is from a GDPR-required country.
     * Includes EU/EEA countries and UK.
     */
    private function is_gdpr_country() {
        // GDPR countries: EU member states + EEA (Norway, Iceland, Liechtenstein) + UK
        $gdpr_countries = array(
            // EU Member States
            'AT', // Austria
            'BE', // Belgium
            'BG', // Bulgaria
            'HR', // Croatia
            'CY', // Cyprus
            'CZ', // Czech Republic
            'DK', // Denmark
            'EE', // Estonia
            'FI', // Finland
            'FR', // France
            'DE', // Germany
            'GR', // Greece
            'HU', // Hungary
            'IE', // Ireland
            'IT', // Italy
            'LV', // Latvia
            'LT', // Lithuania
            'LU', // Luxembourg
            'MT', // Malta
            'NL', // Netherlands
            'PL', // Poland
            'PT', // Portugal
            'RO', // Romania
            'SK', // Slovakia
            'SI', // Slovenia
            'ES', // Spain
            'SE', // Sweden
            // EEA (non-EU)
            'IS', // Iceland
            'LI', // Liechtenstein
            'NO', // Norway
            // UK (post-Brexit, still has similar regulations)
            'GB', // United Kingdom
        );

        // Try to get country from WooCommerce geolocation
        $country = $this->get_visitor_country();

        if (empty($country)) {
            // If we can't determine the country, show the banner to be safe
            return true;
        }

        return in_array(strtoupper($country), $gdpr_countries, true);
    }

    /**
     * Get visitor's country code using WooCommerce geolocation.
     */
    private function get_visitor_country() {
        // Try WooCommerce geolocation first
        if (class_exists('WC_Geolocation')) {
            $geolocation = WC_Geolocation::geolocate_ip();
            if (!empty($geolocation['country'])) {
                return $geolocation['country'];
            }
        }

        // Fallback: check if WooCommerce has stored customer country
        if (function_exists('WC') && WC()->customer) {
            $country = WC()->customer->get_billing_country();
            if (!empty($country)) {
                return $country;
            }
        }

        // Could not determine country
        return '';
    }

    /**
     * Inject Google Consent Mode V2 defaults.
     * This MUST run before GTM loads.
     */
    public function inject_consent_defaults() {
        $consent = $this->get_consent_state();
        $region = get_option('pixelfly_consent_region', 'all');
        $wait_for_update = (int) get_option('pixelfly_consent_wait_ms', 500);

        ?>
<!-- PixelFly Consent Mode V2 -->
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}

// Set consent defaults BEFORE GTM loads
gtag('consent', 'default', {
    'analytics_storage': '<?php echo esc_js($consent['analytics_storage']); ?>',
    'ad_storage': '<?php echo esc_js($consent['ad_storage']); ?>',
    'ad_user_data': '<?php echo esc_js($consent['ad_user_data']); ?>',
    'ad_personalization': '<?php echo esc_js($consent['ad_personalization']); ?>',
    'functionality_storage': '<?php echo esc_js($consent['functionality_storage']); ?>',
    'personalization_storage': '<?php echo esc_js($consent['personalization_storage']); ?>',
    'security_storage': 'granted',
    'wait_for_update': <?php echo $wait_for_update; ?><?php if ($region !== 'all') : ?>,
    'region': <?php echo wp_json_encode(explode(',', $region)); ?><?php endif; ?>
});

// Store consent state for PixelFly tracking
window.pixelflyConsent = <?php echo wp_json_encode($consent); ?>;
</script>
<!-- End PixelFly Consent Mode V2 -->
        <?php
    }

    /**
     * Enqueue consent assets.
     */
    public function enqueue_assets() {
        // Assets are now rendered inline in the banner for simplicity
    }

    /**
     * Get banner CSS styles.
     */
    private function get_banner_styles() {
        $position = get_option('pixelfly_consent_position', 'bottom');
        $btn_color = get_option('pixelfly_consent_btn_color', '#3b82f6');
        $text_color = get_option('pixelfly_consent_text_color', '#ffffff');
        $bg_color = get_option('pixelfly_consent_bg_color', '#1f2937');

        $position_css = $position === 'top' ? 'top: 0;' : 'bottom: 0;';

        return "
        .pixelfly-consent-banner {
            position: fixed;
            left: 0;
            right: 0;
            {$position_css}
            z-index: 999999;
            background: {$bg_color};
            box-shadow: 0 -2px 20px rgba(0,0,0,0.1);
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            animation: pixelflySlideIn 0.3s ease-out;
        }
        @keyframes pixelflySlideIn {
            from { transform: translateY(" . ($position === 'top' ? '-100%' : '100%') . "); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .pixelfly-consent-banner.pixelfly-hidden {
            display: none !important;
        }
        .pixelfly-consent-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 20px;
        }
        .pixelfly-consent-text {
            flex: 1;
            min-width: 280px;
            color: {$text_color};
            font-size: 14px;
            line-height: 1.5;
        }
        .pixelfly-consent-text h4 {
            margin: 0 0 8px 0;
            font-size: 16px;
            font-weight: 600;
            color: {$text_color};
        }
        .pixelfly-consent-text p {
            margin: 0;
        }
        .pixelfly-consent-text a {
            color: {$btn_color};
            text-decoration: underline;
        }
        .pixelfly-consent-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pixelfly-consent-btn {
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .pixelfly-consent-btn-accept {
            background: {$btn_color};
            color: #ffffff;
        }
        .pixelfly-consent-btn-accept:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .pixelfly-consent-btn-reject {
            background: transparent;
            color: {$text_color};
            border: 2px solid {$text_color};
        }
        .pixelfly-consent-btn-reject:hover {
            background: {$text_color};
            color: {$bg_color};
        }
        .pixelfly-consent-btn-settings {
            background: transparent;
            color: {$btn_color};
            padding: 12px 16px;
        }
        .pixelfly-consent-btn-settings:hover {
            text-decoration: underline;
        }
        .pixelfly-consent-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999999;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .pixelfly-consent-modal.pixelfly-visible {
            display: flex;
        }
        .pixelfly-consent-modal-content {
            background: {$bg_color};
            border-radius: 12px;
            max-width: 500px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 24px;
        }
        .pixelfly-consent-modal h3 {
            margin: 0 0 16px 0;
            font-size: 20px;
            color: {$text_color};
        }
        .pixelfly-consent-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 0;
            border-bottom: 1px solid #eee;
        }
        .pixelfly-consent-option:last-child {
            border-bottom: none;
        }
        .pixelfly-consent-option input[type='checkbox'] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            accent-color: {$btn_color};
        }
        .pixelfly-consent-option-text h5 {
            margin: 0 0 4px 0;
            font-size: 15px;
            color: {$text_color};
        }
        .pixelfly-consent-option-text p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }
        .pixelfly-consent-modal-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        @media (max-width: 600px) {
            .pixelfly-consent-inner {
                flex-direction: column;
                text-align: center;
            }
            .pixelfly-consent-actions {
                justify-content: center;
                width: 100%;
            }
            .pixelfly-consent-btn {
                flex: 1;
            }
        }
        ";
    }

    /**
     * Render consent banner.
     */
    public function render_consent_banner() {
        if (!$this->should_show_banner()) {
            return;
        }

        $title = get_option('pixelfly_consent_title', '');
        $title = !empty($title) ? $title : __('We value your privacy', 'pixelfly-woocommerce');

        $message = get_option('pixelfly_consent_message', '');
        $message = !empty($message) ? $message : __('We use cookies to enhance your browsing experience, serve personalized ads or content, and analyze our traffic. By clicking "Accept All", you consent to our use of cookies.', 'pixelfly-woocommerce');

        $privacy_url = get_option('pixelfly_consent_privacy_url', get_privacy_policy_url());

        $accept_text = get_option('pixelfly_consent_accept_text', '');
        $accept_text = !empty($accept_text) ? $accept_text : __('Accept All', 'pixelfly-woocommerce');

        $reject_text = get_option('pixelfly_consent_reject_text', '');
        $reject_text = !empty($reject_text) ? $reject_text : __('Reject All', 'pixelfly-woocommerce');

        $settings_text = get_option('pixelfly_consent_settings_text', '');
        $settings_text = !empty($settings_text) ? $settings_text : __('Cookie Settings', 'pixelfly-woocommerce');

        ?>
<!-- PixelFly Consent Banner -->
<style><?php echo $this->get_banner_styles(); ?></style>
<div id="pixelfly-consent-banner" class="pixelfly-consent-banner">
    <div class="pixelfly-consent-inner">
        <div class="pixelfly-consent-text">
            <h4><?php echo esc_html($title); ?></h4>
            <p>
                <?php echo esc_html($message); ?>
                <?php if ($privacy_url) : ?>
                    <a href="<?php echo esc_url($privacy_url); ?>" target="_blank"><?php esc_html_e('Privacy Policy', 'pixelfly-woocommerce'); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <div class="pixelfly-consent-actions">
            <button type="button" class="pixelfly-consent-btn pixelfly-consent-btn-reject" onclick="pixelflyConsent.reject()">
                <?php echo esc_html($reject_text); ?>
            </button>
            <button type="button" class="pixelfly-consent-btn pixelfly-consent-btn-settings" onclick="pixelflyConsent.showSettings()">
                <?php echo esc_html($settings_text); ?>
            </button>
            <button type="button" class="pixelfly-consent-btn pixelfly-consent-btn-accept" onclick="pixelflyConsent.acceptAll()">
                <?php echo esc_html($accept_text); ?>
            </button>
        </div>
    </div>
</div>

<!-- PixelFly Consent Settings Modal -->
<div id="pixelfly-consent-modal" class="pixelfly-consent-modal">
    <div class="pixelfly-consent-modal-content">
        <h3><?php esc_html_e('Cookie Settings', 'pixelfly-woocommerce'); ?></h3>

        <div class="pixelfly-consent-option">
            <input type="checkbox" id="pf-consent-necessary" checked disabled>
            <div class="pixelfly-consent-option-text">
                <h5><?php esc_html_e('Necessary', 'pixelfly-woocommerce'); ?></h5>
                <p><?php esc_html_e('Essential for the website to function. Cannot be disabled.', 'pixelfly-woocommerce'); ?></p>
            </div>
        </div>

        <div class="pixelfly-consent-option">
            <input type="checkbox" id="pf-consent-analytics">
            <div class="pixelfly-consent-option-text">
                <h5><?php esc_html_e('Analytics', 'pixelfly-woocommerce'); ?></h5>
                <p><?php esc_html_e('Help us understand how visitors interact with our website.', 'pixelfly-woocommerce'); ?></p>
            </div>
        </div>

        <div class="pixelfly-consent-option">
            <input type="checkbox" id="pf-consent-marketing">
            <div class="pixelfly-consent-option-text">
                <h5><?php esc_html_e('Marketing', 'pixelfly-woocommerce'); ?></h5>
                <p><?php esc_html_e('Used to deliver personalized ads and measure ad performance.', 'pixelfly-woocommerce'); ?></p>
            </div>
        </div>

        <div class="pixelfly-consent-option">
            <input type="checkbox" id="pf-consent-personalization">
            <div class="pixelfly-consent-option-text">
                <h5><?php esc_html_e('Personalization', 'pixelfly-woocommerce'); ?></h5>
                <p><?php esc_html_e('Remember your preferences and personalize your experience.', 'pixelfly-woocommerce'); ?></p>
            </div>
        </div>

        <div class="pixelfly-consent-modal-actions">
            <button type="button" class="pixelfly-consent-btn pixelfly-consent-btn-reject" onclick="pixelflyConsent.hideSettings()">
                <?php esc_html_e('Cancel', 'pixelfly-woocommerce'); ?>
            </button>
            <button type="button" class="pixelfly-consent-btn pixelfly-consent-btn-accept" onclick="pixelflyConsent.saveSettings()">
                <?php esc_html_e('Save Settings', 'pixelfly-woocommerce'); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var pixelflyConsent = window.pixelflyConsent || {};
    var banner = document.getElementById('pixelfly-consent-banner');
    var modal = document.getElementById('pixelfly-consent-modal');
    var cookieName = '<?php echo esc_js(self::CONSENT_COOKIE); ?>';
    var cookieDays = <?php echo self::CONSENT_DURATION; ?>;

    // Update Google Consent Mode
    function updateConsent(consent) {
        if (typeof gtag === 'function') {
            gtag('consent', 'update', consent);
        }

        // Update dataLayer
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            'event': 'consent_update',
            'consent': consent
        });

        // Store for PixelFly
        window.pixelflyConsent = consent;
    }

    // Set cookie
    function setCookie(value) {
        var expires = new Date();
        expires.setTime(expires.getTime() + (cookieDays * 24 * 60 * 60 * 1000));
        document.cookie = cookieName + '=' + encodeURIComponent(JSON.stringify(value)) +
                         ';expires=' + expires.toUTCString() +
                         ';path=/;SameSite=Lax';
    }

    // Hide banner
    function hideBanner() {
        if (banner) {
            banner.classList.add('pixelfly-hidden');
        }
    }

    // Accept all
    pixelflyConsent.acceptAll = function() {
        var consent = {
            'analytics_storage': 'granted',
            'ad_storage': 'granted',
            'ad_user_data': 'granted',
            'ad_personalization': 'granted',
            'functionality_storage': 'granted',
            'personalization_storage': 'granted',
            'security_storage': 'granted'
        };
        updateConsent(consent);
        setCookie(consent);
        hideBanner();
    };

    // Reject all (essential only)
    pixelflyConsent.reject = function() {
        var consent = {
            'analytics_storage': 'denied',
            'ad_storage': 'denied',
            'ad_user_data': 'denied',
            'ad_personalization': 'denied',
            'functionality_storage': 'denied',
            'personalization_storage': 'denied',
            'security_storage': 'granted'
        };
        updateConsent(consent);
        setCookie(consent);
        hideBanner();
    };

    // Show settings modal
    pixelflyConsent.showSettings = function() {
        if (modal) {
            // Pre-check all options by default (so Save Settings = Accept All)
            document.getElementById('pf-consent-analytics').checked = true;
            document.getElementById('pf-consent-marketing').checked = true;
            document.getElementById('pf-consent-personalization').checked = true;
            modal.classList.add('pixelfly-visible');
        }
    };

    // Hide settings modal
    pixelflyConsent.hideSettings = function() {
        if (modal) {
            modal.classList.remove('pixelfly-visible');
        }
    };

    // Save custom settings
    pixelflyConsent.saveSettings = function() {
        var analytics = document.getElementById('pf-consent-analytics').checked;
        var marketing = document.getElementById('pf-consent-marketing').checked;
        var personalization = document.getElementById('pf-consent-personalization').checked;

        var consent = {
            'analytics_storage': analytics ? 'granted' : 'denied',
            'ad_storage': marketing ? 'granted' : 'denied',
            'ad_user_data': marketing ? 'granted' : 'denied',
            'ad_personalization': marketing ? 'granted' : 'denied',
            'functionality_storage': 'granted',
            'personalization_storage': personalization ? 'granted' : 'denied',
            'security_storage': 'granted'
        };
        updateConsent(consent);
        setCookie(consent);
        pixelflyConsent.hideSettings();
        hideBanner();
    };

    // Close modal on outside click
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                pixelflyConsent.hideSettings();
            }
        });
    }

    // Expose globally
    window.pixelflyConsent = pixelflyConsent;
})();
</script>
<!-- End PixelFly Consent Banner -->
        <?php
    }

    /**
     * AJAX handler for saving consent.
     */
    public function ajax_save_consent() {
        check_ajax_referer('pixelfly_nonce', 'nonce');

        $consent = isset($_POST['consent']) ? json_decode(stripslashes($_POST['consent']), true) : null;

        if (!is_array($consent)) {
            wp_send_json_error('Invalid consent data');
        }

        // Sanitize consent values
        $allowed_values = array('granted', 'denied');
        $consent_types = array(
            'analytics_storage',
            'ad_storage',
            'ad_user_data',
            'ad_personalization',
            'functionality_storage',
            'personalization_storage',
            'security_storage',
        );

        $sanitized = array();
        foreach ($consent_types as $type) {
            $value = isset($consent[$type]) && in_array($consent[$type], $allowed_values)
                ? $consent[$type]
                : 'denied';
            $sanitized[$type] = $value;
        }

        // Security storage is always granted
        $sanitized['security_storage'] = 'granted';

        // Set cookie
        $expiry = time() + (self::CONSENT_DURATION * DAY_IN_SECONDS);
        setcookie(
            self::CONSENT_COOKIE,
            wp_json_encode($sanitized),
            $expiry,
            '/',
            '',
            is_ssl(),
            false
        );

        wp_send_json_success(array('consent' => $sanitized));
    }

    /**
     * Check if tracking should be blocked based on consent.
     */
    public function should_block_tracking($type = 'analytics') {
        if (!$this->is_consent_enabled()) {
            return false; // No consent management = allow tracking
        }

        switch ($type) {
            case 'analytics':
                return !$this->has_consent('analytics_storage');
            case 'marketing':
            case 'ads':
                return !$this->has_consent('ad_storage');
            case 'personalization':
                return !$this->has_consent('personalization_storage');
            default:
                return false;
        }
    }
}
