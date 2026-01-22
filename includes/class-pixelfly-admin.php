<?php

/**
 * PixelFly Admin
 *
 * Admin settings page and pending events management
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Admin
{

    /**
     * Constructor
     */
    public function __construct()
    {
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_pixelfly_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_pixelfly_test_custom_domain', [$this, 'ajax_test_custom_domain']);
        add_action('wp_ajax_pixelfly_fire_event', [$this, 'ajax_fire_event']);
        add_action('wp_ajax_pixelfly_delete_event', [$this, 'ajax_delete_event']);
        add_action('wp_ajax_pixelfly_fire_all_events', [$this, 'ajax_fire_all_events']);

        // Add pending events count to menu
        add_filter('add_menu_classes', [$this, 'add_pending_count_bubble']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            __('PixelFly', 'pixelfly-woocommerce'),
            __('PixelFly', 'pixelfly-woocommerce'),
            'manage_woocommerce',
            'pixelfly',
            [$this, 'render_settings_page'],
            'dashicons-chart-area',
            56
        );

        // Settings submenu
        add_submenu_page(
            'pixelfly',
            __('Settings', 'pixelfly-woocommerce'),
            __('Settings', 'pixelfly-woocommerce'),
            'manage_woocommerce',
            'pixelfly',
            [$this, 'render_settings_page']
        );

        // Pending Events submenu
        $pending_count = PixelFly_Delayed::get_pending_count();
        $pending_label = __('Pending Events', 'pixelfly-woocommerce');
        if ($pending_count > 0) {
            $pending_label .= ' <span class="awaiting-mod">' . $pending_count . '</span>';
        }

        add_submenu_page(
            'pixelfly',
            __('Pending Events', 'pixelfly-woocommerce'),
            $pending_label,
            'manage_woocommerce',
            'pixelfly-pending',
            [$this, 'render_pending_events_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings()
    {
        // API settings
        register_setting('pixelfly_settings', 'pixelfly_enabled');
        register_setting('pixelfly_settings', 'pixelfly_api_key');
        register_setting('pixelfly_settings', 'pixelfly_endpoint');

        // DataLayer settings
        register_setting('pixelfly_settings', 'pixelfly_datalayer_enabled');
        register_setting('pixelfly_settings', 'pixelfly_gtm_container_id');

        // Custom Loader settings
        register_setting('pixelfly_settings', 'pixelfly_custom_loader_enabled');
        register_setting('pixelfly_settings', 'pixelfly_custom_loader_domain', [
            'sanitize_callback' => [$this, 'sanitize_custom_domain'],
        ]);

        // Consent Mode V2 settings
        register_setting('pixelfly_settings', 'pixelfly_consent_enabled');
        register_setting('pixelfly_settings', 'pixelfly_consent_mode');
        register_setting('pixelfly_settings', 'pixelfly_consent_position');
        register_setting('pixelfly_settings', 'pixelfly_consent_title');
        register_setting('pixelfly_settings', 'pixelfly_consent_message');
        register_setting('pixelfly_settings', 'pixelfly_consent_privacy_url');
        register_setting('pixelfly_settings', 'pixelfly_consent_accept_text');
        register_setting('pixelfly_settings', 'pixelfly_consent_reject_text');
        register_setting('pixelfly_settings', 'pixelfly_consent_settings_text');
        register_setting('pixelfly_settings', 'pixelfly_consent_btn_color');
        register_setting('pixelfly_settings', 'pixelfly_consent_bg_color');
        register_setting('pixelfly_settings', 'pixelfly_consent_text_color');
        register_setting('pixelfly_settings', 'pixelfly_consent_region');
        register_setting('pixelfly_settings', 'pixelfly_consent_wait_ms');

        // Delayed events settings
        register_setting('pixelfly_settings', 'pixelfly_delayed_enabled');
        register_setting('pixelfly_settings', 'pixelfly_delayed_payment_methods');
        register_setting('pixelfly_settings', 'pixelfly_delayed_fire_on_status');

        // Advanced settings
        register_setting('pixelfly_settings', 'pixelfly_debug_mode');
        register_setting('pixelfly_settings', 'pixelfly_event_logging');
        register_setting('pixelfly_settings', 'pixelfly_excluded_roles');
    }

    /**
     * Sanitize custom domain input.
     */
    public function sanitize_custom_domain($domain)
    {
        if (empty($domain)) {
            return '';
        }

        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Validate format
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
            add_settings_error(
                'pixelfly_custom_loader_domain',
                'invalid_domain',
                __('Invalid custom domain format. Please enter a valid subdomain (e.g., t.yourstore.com).', 'pixelfly-woocommerce')
            );
            return get_option('pixelfly_custom_loader_domain', '');
        }

        return sanitize_text_field($domain);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'pixelfly') === false) {
            return;
        }

        wp_enqueue_style(
            'pixelfly-admin',
            PIXELFLY_WC_PLUGIN_URL . 'admin/css/admin.css',
            [],
            PIXELFLY_WC_VERSION
        );

        wp_enqueue_script(
            'pixelfly-admin',
            PIXELFLY_WC_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            PIXELFLY_WC_VERSION,
            true
        );

        wp_localize_script('pixelfly-admin', 'pixelflyAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pixelfly_admin_nonce'),
            'strings' => [
                'testing' => __('Testing...', 'pixelfly-woocommerce'),
                'success' => __('Connection successful!', 'pixelfly-woocommerce'),
                'error' => __('Connection failed', 'pixelfly-woocommerce'),
                'firing' => __('Firing...', 'pixelfly-woocommerce'),
                'fired' => __('Fired!', 'pixelfly-woocommerce'),
                'confirmDelete' => __('Are you sure you want to delete this event?', 'pixelfly-woocommerce'),
                'confirmFireAll' => __('Are you sure you want to fire all pending events?', 'pixelfly-woocommerce'),
            ],
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        include PIXELFLY_WC_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Render pending events page
     */
    public function render_pending_events_page()
    {
        include PIXELFLY_WC_PLUGIN_DIR . 'admin/views/pending-events.php';
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('pixelfly_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $api = new PixelFly_API();
        $result = $api->test_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Test custom domain connection
     */
    public function ajax_test_custom_domain()
    {
        check_ajax_referer('pixelfly_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
        $gtm_id = isset($_POST['gtm_id']) ? sanitize_text_field($_POST['gtm_id']) : 'GTM-TEST123';

        if (empty($domain)) {
            wp_send_json_error(['message' => __('Please enter a custom domain.', 'pixelfly-woocommerce')]);
        }

        // Validate domain format
        $validated = PixelFly_Custom_Loader::validate_domain($domain);
        if (is_wp_error($validated)) {
            wp_send_json_error(['message' => $validated->get_error_message()]);
        }

        // Test the domain
        $result = PixelFly_Custom_Loader::test_custom_domain($validated, $gtm_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Fire single event
     */
    public function ajax_fire_event()
    {
        check_ajax_referer('pixelfly_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (!$event_id) {
            wp_send_json_error(['message' => 'Invalid event ID']);
        }

        $result = PixelFly_Delayed::fire_event($event_id);

        if ($result) {
            wp_send_json_success(['message' => 'Event fired successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to fire event']);
        }
    }

    /**
     * AJAX: Delete event
     */
    public function ajax_delete_event()
    {
        check_ajax_referer('pixelfly_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        if (!$event_id) {
            wp_send_json_error(['message' => 'Invalid event ID']);
        }

        $result = PixelFly_Delayed::delete_event($event_id);

        if ($result) {
            wp_send_json_success(['message' => 'Event deleted']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete event']);
        }
    }

    /**
     * AJAX: Fire all pending events
     */
    public function ajax_fire_all_events()
    {
        check_ajax_referer('pixelfly_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $pending_events = PixelFly_Delayed::get_pending_events(100);
        $fired = 0;
        $failed = 0;

        foreach ($pending_events as $event) {
            $result = PixelFly_Delayed::fire_event($event->id);
            if ($result) {
                $fired++;
            } else {
                $failed++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Fired %d events, %d failed', 'pixelfly-woocommerce'),
                $fired,
                $failed
            ),
            'fired' => $fired,
            'failed' => $failed,
        ]);
    }

    /**
     * Add pending count bubble to menu
     */
    public function add_pending_count_bubble($menu)
    {
        return $menu;
    }

    /**
     * Get available payment methods
     */
    public static function get_payment_methods()
    {
        if (!class_exists('WC_Payment_Gateways')) {
            return [];
        }

        $gateways = WC_Payment_Gateways::instance()->payment_gateways();
        $methods = [];

        foreach ($gateways as $id => $gateway) {
            if ($gateway->enabled === 'yes') {
                $methods[$id] = $gateway->get_title();
            }
        }

        return $methods;
    }

    /**
     * Get available order statuses
     */
    public static function get_order_statuses()
    {
        $statuses = wc_get_order_statuses();
        $clean = [];

        foreach ($statuses as $key => $label) {
            $clean[str_replace('wc-', '', $key)] = $label;
        }

        return $clean;
    }
}
