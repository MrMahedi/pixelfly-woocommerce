<?php

/**
 * Plugin Name: PixelFly for WooCommerce
 * Plugin URI: https://pixelfly.io
 * Description: Server-side event tracking for Meta CAPI & GA4 via PixelFly. Includes dataLayer support and delayed purchase events for COD orders.
 * Version: 1.0.0
 * Author: PixelFly
 * Author URI: https://pixelfly.io
 * Text Domain: pixelfly-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('PIXELFLY_WC_VERSION', '1.0.0');
define('PIXELFLY_WC_PLUGIN_FILE', __FILE__);
define('PIXELFLY_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIXELFLY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIXELFLY_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main PixelFly WooCommerce Class
 */
final class PixelFly_WooCommerce
{

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Check if WooCommerce is active
        add_action('plugins_loaded', [$this, 'init']);

        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Load textdomain
        add_action('init', [$this, 'load_textdomain']);

        // Set Facebook _fbc cookie early (before headers sent)
        // Priority 1 to run before most other init hooks
        add_action('init', [$this, 'init_facebook_cookies'], 1);

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
    }

    /**
     * Initialize Facebook cookies (_fbc from fbclid)
     * Must run early before headers are sent
     */
    public function init_facebook_cookies()
    {
        // Only on frontend
        if (is_admin()) {
            return;
        }

        // Load user data class if not already loaded
        if (!class_exists('PixelFly_User_Data')) {
            require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-user-data.php';
        }

        PixelFly_User_Data::init();
    }

    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Initialize plugin after plugins loaded
     */
    public function init()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Load classes
        $this->load_includes();

        // Initialize components
        if (is_admin()) {
            new PixelFly_Admin();
        }

        // DataLayer works independently (for GTM) - only requires datalayer_enabled
        if (get_option('pixelfly_datalayer_enabled', true)) {
            new PixelFly_DataLayer();
        }

        // Server-side tracking requires API key
        if ($this->is_enabled()) {
            new PixelFly_Tracker();
            new PixelFly_Delayed();
            new PixelFly_UTM_Capture();
        }
    }

    /**
     * Load required files
     */
    private function load_includes()
    {
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-admin.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-api.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-user-data.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-events.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-datalayer.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-tracker.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-delayed.php';
        require_once PIXELFLY_WC_PLUGIN_DIR . 'includes/class-pixelfly-utm-capture.php';
    }

    /**
     * Check if plugin is enabled
     */
    public function is_enabled()
    {
        return get_option('pixelfly_enabled', true) && get_option('pixelfly_api_key', '');
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            event_data LONGTEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            fired_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_order_id (order_id),
            INDEX idx_status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Event log table
        $log_table = $wpdb->prefix . 'pixelfly_event_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $log_table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            event_id VARCHAR(100) NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            response_code INT NULL,
            response_body TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_order_id (order_id)
        ) $charset_collate;";
        dbDelta($sql_log);

        // Set default options
        add_option('pixelfly_enabled', true);
        add_option('pixelfly_api_key', '');
        add_option('pixelfly_endpoint', 'https://track.pixelfly.io/e');
        add_option('pixelfly_datalayer_enabled', true);
        add_option('pixelfly_delayed_enabled', true);
        add_option('pixelfly_delayed_payment_methods', ['cod']);
        add_option('pixelfly_delayed_fire_on_status', ['processing', 'completed']);
        add_option('pixelfly_debug_mode', false);
        add_option('pixelfly_event_logging', false);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        flush_rewrite_rules();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'pixelfly-woocommerce',
            false,
            dirname(PIXELFLY_WC_PLUGIN_BASENAME) . '/languages/'
        );
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice()
    {
?>
        <div class="error">
            <p><?php esc_html_e('PixelFly for WooCommerce requires WooCommerce to be installed and activated.', 'pixelfly-woocommerce'); ?></p>
        </div>
<?php
    }
}

/**
 * Initialize the plugin
 */
function pixelfly_wc()
{
    return PixelFly_WooCommerce::instance();
}

// Start the plugin
pixelfly_wc();
