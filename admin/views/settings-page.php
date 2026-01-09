<?php

/**
 * PixelFly Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$enabled = get_option('pixelfly_enabled', true);
$api_key = get_option('pixelfly_api_key', '');
$endpoint = get_option('pixelfly_endpoint', 'https://track.pixelfly.io/e');
$datalayer_enabled = get_option('pixelfly_datalayer_enabled', true);
$gtm_container_id = get_option('pixelfly_gtm_container_id', '');
$delayed_enabled = get_option('pixelfly_delayed_enabled', true);
$delayed_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
$delayed_statuses = get_option('pixelfly_delayed_fire_on_status', ['processing', 'completed']);
$debug_mode = get_option('pixelfly_debug_mode', false);
$event_logging = get_option('pixelfly_event_logging', false);

$payment_methods = PixelFly_Admin::get_payment_methods();
$order_statuses = PixelFly_Admin::get_order_statuses();
?>

<div class="wrap pixelfly-settings">
    <h1>
        <img src="<?php echo esc_url(PIXELFLY_WC_PLUGIN_URL . 'admin/images/logo.png'); ?>" alt="PixelFly" style="height: 30px; vertical-align: middle; margin-right: 10px;">
        <?php esc_html_e('PixelFly Settings', 'pixelfly-woocommerce'); ?>
    </h1>

    <form method="post" action="options.php">
        <?php settings_fields('pixelfly_settings'); ?>

        <!-- API Configuration -->
        <div class="pixelfly-card">
            <h2><?php esc_html_e('API Configuration', 'pixelfly-woocommerce'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Tracking', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_enabled" value="1" <?php checked($enabled); ?>>
                            <?php esc_html_e('Enable PixelFly tracking', 'pixelfly-woocommerce'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_api_key"><?php esc_html_e('API Key', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pixelfly_api_key" name="pixelfly_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="pf_live_xxxxx">
                        <p class="description"><?php esc_html_e('Your PixelFly container API key. Find it in your PixelFly dashboard.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_endpoint"><?php esc_html_e('Endpoint URL', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="pixelfly_endpoint" name="pixelfly_endpoint" value="<?php echo esc_attr($endpoint); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Change this only if using a custom tracking domain.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" id="pixelfly-test-connection" class="button">
                            <?php esc_html_e('Test Connection', 'pixelfly-woocommerce'); ?>
                        </button>
                        <span id="pixelfly-test-result" style="margin-left: 10px;"></span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- DataLayer Settings -->
        <div class="pixelfly-card">
            <h2><?php esc_html_e('DataLayer Settings', 'pixelfly-woocommerce'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable DataLayer', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_datalayer_enabled" value="1" <?php checked($datalayer_enabled); ?>>
                            <?php esc_html_e('Output GA4-compatible dataLayer events for GTM', 'pixelfly-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Enable this if you want to use the events with Google Tag Manager.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_gtm_container_id"><?php esc_html_e('GTM Container ID', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pixelfly_gtm_container_id" name="pixelfly_gtm_container_id" value="<?php echo esc_attr($gtm_container_id); ?>" class="regular-text" placeholder="GTM-XXXXXXX">
                        <p class="description"><?php esc_html_e('Enter your Google Tag Manager container ID (e.g., GTM-ABC123). PixelFly will automatically inject the GTM script if provided.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Delayed Purchase Events -->
        <div class="pixelfly-card">
            <h2><?php esc_html_e('Delayed Purchase Events', 'pixelfly-woocommerce'); ?></h2>
            <p class="description"><?php esc_html_e('For COD/manual payment orders, store purchase events and fire them when the order is confirmed.', 'pixelfly-woocommerce'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Delayed Events', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_delayed_enabled" value="1" <?php checked($delayed_enabled); ?> id="pixelfly_delayed_enabled">
                            <?php esc_html_e('Enable delayed purchase events for manual payments', 'pixelfly-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, purchase events will fire immediately on thank you page for ALL payment methods.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="delayed-setting">
                    <th scope="row"><?php esc_html_e('Payment Methods', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <?php if (!empty($payment_methods)): ?>
                            <?php foreach ($payment_methods as $id => $title): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="pixelfly_delayed_payment_methods[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, (array) $delayed_methods)); ?>>
                                    <?php echo esc_html($title); ?> <code><?php echo esc_html($id); ?></code>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php esc_html_e('No payment methods available.', 'pixelfly-woocommerce'); ?></p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Select payment methods that should use delayed purchase events.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="delayed-setting">
                    <th scope="row"><?php esc_html_e('Fire on Status Change', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <?php foreach ($order_statuses as $status => $label): ?>
                            <?php if (in_array($status, ['pending', 'on-hold', 'processing', 'completed'])): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="pixelfly_delayed_fire_on_status[]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, (array) $delayed_statuses)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('Fire delayed purchase events when order status changes to these statuses.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Advanced Settings -->
        <div class="pixelfly-card">
            <h2><?php esc_html_e('Advanced Settings', 'pixelfly-woocommerce'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Debug Mode', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_debug_mode" value="1" <?php checked($debug_mode); ?>>
                            <?php esc_html_e('Enable debug mode (logs events to browser console)', 'pixelfly-woocommerce'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Event Logging', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_event_logging" value="1" <?php checked($event_logging); ?>>
                            <?php esc_html_e('Log all events to database for debugging', 'pixelfly-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Enable only for debugging. This will increase database size.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<style>
    .pixelfly-settings .pixelfly-card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .pixelfly-settings .pixelfly-card h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .pixelfly-settings .form-table th {
        width: 200px;
    }

    #pixelfly-test-result.success {
        color: #46b450;
    }

    #pixelfly-test-result.error {
        color: #dc3232;
    }
</style>

<script>
    jQuery(document).ready(function($) {
        // Toggle delayed settings visibility
        function toggleDelayedSettings() {
            var enabled = $('#pixelfly_delayed_enabled').is(':checked');
            $('.delayed-setting').toggle(enabled);
        }

        $('#pixelfly_delayed_enabled').on('change', toggleDelayedSettings);
        toggleDelayedSettings();
    });
</script>