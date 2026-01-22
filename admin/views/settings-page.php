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
$excluded_roles = get_option('pixelfly_excluded_roles', []);

// Custom Loader settings
$custom_loader_enabled = get_option('pixelfly_custom_loader_enabled', false);
$custom_loader_domain = get_option('pixelfly_custom_loader_domain', '');

// Consent Mode V2 settings
$consent_enabled = get_option('pixelfly_consent_enabled', false);
$consent_mode = get_option('pixelfly_consent_mode', 'opt-in');
$consent_position = get_option('pixelfly_consent_position', 'bottom');
$consent_bg_color = get_option('pixelfly_consent_bg_color', '#1f2937');
$consent_text_color = get_option('pixelfly_consent_text_color', '#ffffff');
$consent_btn_color = get_option('pixelfly_consent_btn_color', '#3b82f6');
$consent_title = get_option('pixelfly_consent_title', 'We value your privacy');
$consent_message = get_option('pixelfly_consent_message', 'We use cookies to enhance your browsing experience, serve personalized ads or content, and analyze our traffic. By clicking "Accept All", you consent to our use of cookies.');
$consent_privacy_url = get_option('pixelfly_consent_privacy_url', '');

$payment_methods = PixelFly_Admin::get_payment_methods();
$order_statuses = PixelFly_Admin::get_order_statuses();
$all_roles = wp_roles()->get_names();
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

        <!-- Custom Loader (Ad Blocker Bypass) -->
        <div class="pixelfly-card">
            <h2>
                <?php esc_html_e('Custom Loader', 'pixelfly-woocommerce'); ?>
                <span class="pixelfly-badge pixelfly-badge-new"><?php esc_html_e('New', 'pixelfly-woocommerce'); ?></span>
            </h2>
            <p class="description"><?php esc_html_e('Load GTM/GA4/Meta Pixel scripts through your PixelFly custom domain to bypass ad blockers and improve tracking accuracy.', 'pixelfly-woocommerce'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Custom Loader', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_custom_loader_enabled" value="1" <?php checked($custom_loader_enabled); ?> id="pixelfly_custom_loader_enabled">
                            <?php esc_html_e('Load tracking scripts through PixelFly proxy', 'pixelfly-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, GTM will be loaded via your custom domain instead of googletagmanager.com', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="custom-loader-setting">
                    <th scope="row">
                        <label for="pixelfly_custom_loader_domain"><?php esc_html_e('Custom Domain', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pixelfly_custom_loader_domain" name="pixelfly_custom_loader_domain" value="<?php echo esc_attr($custom_loader_domain); ?>" class="regular-text" placeholder="t.yourstore.com">
                        <p class="description"><?php esc_html_e('Enter your PixelFly custom domain (configured in your PixelFly dashboard). Example: t.yourstore.com', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="custom-loader-setting">
                    <th scope="row"><?php esc_html_e('GTM Container ID', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <?php if (!empty($gtm_container_id)): ?>
                            <code><?php echo esc_html($gtm_container_id); ?></code>
                            <p class="description"><?php esc_html_e('Using GTM ID from DataLayer Settings above.', 'pixelfly-woocommerce'); ?></p>
                        <?php else: ?>
                            <span class="pixelfly-warning"><?php esc_html_e('Please enter your GTM Container ID in the DataLayer Settings section above.', 'pixelfly-woocommerce'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="custom-loader-setting">
                    <th scope="row"></th>
                    <td>
                        <button type="button" id="pixelfly-test-custom-domain" class="button">
                            <?php esc_html_e('Test Custom Domain', 'pixelfly-woocommerce'); ?>
                        </button>
                        <span id="pixelfly-custom-domain-result" style="margin-left: 10px;"></span>
                    </td>
                </tr>
            </table>

            <div class="pixelfly-info-box custom-loader-setting">
                <strong><?php esc_html_e('How it works:', 'pixelfly-woocommerce'); ?></strong>
                <ol>
                    <li><?php esc_html_e('Configure a custom domain in your PixelFly dashboard (e.g., t.yourstore.com)', 'pixelfly-woocommerce'); ?></li>
                    <li><?php esc_html_e('Add a CNAME DNS record pointing to PixelFly', 'pixelfly-woocommerce'); ?></li>
                    <li><?php esc_html_e('Enter your custom domain above and enable Custom Loader', 'pixelfly-woocommerce'); ?></li>
                    <li><?php esc_html_e('GTM and tracking scripts will now load through your domain, bypassing most ad blockers', 'pixelfly-woocommerce'); ?></li>
                </ol>
            </div>
        </div>

        <!-- Consent Mode V2 -->
        <div class="pixelfly-card">
            <h2>
                <?php esc_html_e('Consent Mode V2', 'pixelfly-woocommerce'); ?>
                <span class="pixelfly-badge pixelfly-badge-new"><?php esc_html_e('New', 'pixelfly-woocommerce'); ?></span>
            </h2>
            <p class="description"><?php esc_html_e('Display a cookie consent banner and implement Google Consent Mode V2 for GDPR/CCPA compliance.', 'pixelfly-woocommerce'); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Consent Banner', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pixelfly_consent_enabled" value="1" <?php checked($consent_enabled); ?> id="pixelfly_consent_enabled">
                            <?php esc_html_e('Show cookie consent banner to visitors', 'pixelfly-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Displays a customizable consent banner and sets Google Consent Mode V2 signals.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="consent-setting">
                    <th scope="row"><?php esc_html_e('Show Banner To', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <select name="pixelfly_consent_region" id="pixelfly_consent_region">
                            <option value="all" <?php selected(get_option('pixelfly_consent_region', 'all'), 'all'); ?>><?php esc_html_e('All visitors (worldwide)', 'pixelfly-woocommerce'); ?></option>
                            <option value="gdpr" <?php selected(get_option('pixelfly_consent_region', 'all'), 'gdpr'); ?>><?php esc_html_e('GDPR countries only (EU/EEA + UK)', 'pixelfly-woocommerce'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Choose whether to show the consent banner to all visitors or only to visitors from GDPR-required regions.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="consent-setting">
                    <th scope="row"><?php esc_html_e('Consent Mode', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <select name="pixelfly_consent_mode" id="pixelfly_consent_mode">
                            <option value="opt-in" <?php selected($consent_mode, 'opt-in'); ?>><?php esc_html_e('Opt-in (GDPR) - Deny by default', 'pixelfly-woocommerce'); ?></option>
                            <option value="opt-out" <?php selected($consent_mode, 'opt-out'); ?>><?php esc_html_e('Opt-out (CCPA) - Allow by default', 'pixelfly-woocommerce'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Opt-in: Tracking disabled until consent given (GDPR). Opt-out: Tracking enabled until user opts out (CCPA).', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
                <tr class="consent-setting">
                    <th scope="row"><?php esc_html_e('Banner Position', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <select name="pixelfly_consent_position" id="pixelfly_consent_position">
                            <option value="bottom" <?php selected($consent_position, 'bottom'); ?>><?php esc_html_e('Bottom of page', 'pixelfly-woocommerce'); ?></option>
                            <option value="top" <?php selected($consent_position, 'top'); ?>><?php esc_html_e('Top of page', 'pixelfly-woocommerce'); ?></option>
                            <option value="bottom-left" <?php selected($consent_position, 'bottom-left'); ?>><?php esc_html_e('Bottom left corner', 'pixelfly-woocommerce'); ?></option>
                            <option value="bottom-right" <?php selected($consent_position, 'bottom-right'); ?>><?php esc_html_e('Bottom right corner', 'pixelfly-woocommerce'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <h3 class="consent-setting"><?php esc_html_e('Banner Appearance', 'pixelfly-woocommerce'); ?></h3>
            <table class="form-table consent-setting">
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_title"><?php esc_html_e('Banner Title', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pixelfly_consent_title" name="pixelfly_consent_title" value="<?php echo esc_attr($consent_title); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_message"><?php esc_html_e('Banner Message', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <textarea id="pixelfly_consent_message" name="pixelfly_consent_message" class="large-text" rows="3"><?php echo esc_textarea($consent_message); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_privacy_url"><?php esc_html_e('Privacy Policy URL', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="pixelfly_consent_privacy_url" name="pixelfly_consent_privacy_url" value="<?php echo esc_attr($consent_privacy_url); ?>" class="regular-text" placeholder="https://yourstore.com/privacy-policy">
                        <p class="description"><?php esc_html_e('Link to your privacy policy page. Leave empty to hide the link.', 'pixelfly-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>

            <h3 class="consent-setting"><?php esc_html_e('Banner Colors', 'pixelfly-woocommerce'); ?></h3>
            <table class="form-table consent-setting">
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_bg_color"><?php esc_html_e('Background Color', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="color" id="pixelfly_consent_bg_color" name="pixelfly_consent_bg_color" value="<?php echo esc_attr($consent_bg_color); ?>">
                        <code><?php echo esc_html($consent_bg_color); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_text_color"><?php esc_html_e('Text Color', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="color" id="pixelfly_consent_text_color" name="pixelfly_consent_text_color" value="<?php echo esc_attr($consent_text_color); ?>">
                        <code><?php echo esc_html($consent_text_color); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pixelfly_consent_btn_color"><?php esc_html_e('Button Color', 'pixelfly-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="color" id="pixelfly_consent_btn_color" name="pixelfly_consent_btn_color" value="<?php echo esc_attr($consent_btn_color); ?>">
                        <code><?php echo esc_html($consent_btn_color); ?></code>
                    </td>
                </tr>
            </table>

            <div class="pixelfly-info-box consent-setting">
                <strong><?php esc_html_e('Google Consent Mode V2 Signals:', 'pixelfly-woocommerce'); ?></strong>
                <ul>
                    <li><code>analytics_storage</code> - <?php esc_html_e('Controls Google Analytics cookies', 'pixelfly-woocommerce'); ?></li>
                    <li><code>ad_storage</code> - <?php esc_html_e('Controls advertising cookies', 'pixelfly-woocommerce'); ?></li>
                    <li><code>ad_user_data</code> - <?php esc_html_e('Controls sending user data for advertising', 'pixelfly-woocommerce'); ?></li>
                    <li><code>ad_personalization</code> - <?php esc_html_e('Controls personalized advertising', 'pixelfly-woocommerce'); ?></li>
                </ul>
            </div>
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
                <tr>
                    <th scope="row"><?php esc_html_e('Exclude User Roles', 'pixelfly-woocommerce'); ?></th>
                    <td>
                        <?php foreach ($all_roles as $role_key => $role_name): ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="pixelfly_excluded_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $excluded_roles)); ?>>
                                <?php echo esc_html($role_name); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('Disable tracking for users with these roles. Useful for excluding administrators and shop managers from analytics.', 'pixelfly-woocommerce'); ?></p>
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

    #pixelfly-test-result.error,
    #pixelfly-custom-domain-result.error {
        color: #dc3232;
    }

    #pixelfly-custom-domain-result.success {
        color: #46b450;
    }

    .pixelfly-badge {
        display: inline-block;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        border-radius: 3px;
        margin-left: 8px;
        vertical-align: middle;
    }

    .pixelfly-badge-new {
        background: #3b82f6;
        color: #fff;
    }

    .pixelfly-warning {
        color: #dba617;
        font-style: italic;
    }

    .pixelfly-info-box {
        background: #f0f6fc;
        border-left: 4px solid #3b82f6;
        padding: 12px 16px;
        margin-top: 16px;
        border-radius: 0 4px 4px 0;
    }

    .pixelfly-info-box strong {
        display: block;
        margin-bottom: 8px;
    }

    .pixelfly-info-box ol,
    .pixelfly-info-box ul {
        margin: 0;
        padding-left: 20px;
    }

    .pixelfly-info-box li {
        margin-bottom: 4px;
    }

    .pixelfly-info-box code {
        background: #e7f0f8;
        padding: 1px 4px;
    }

    .pixelfly-settings input[type="color"] {
        width: 50px;
        height: 30px;
        padding: 0;
        border: 1px solid #ccd0d4;
        border-radius: 3px;
        cursor: pointer;
        vertical-align: middle;
        margin-right: 8px;
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

        // Toggle custom loader settings visibility
        function toggleCustomLoaderSettings() {
            var enabled = $('#pixelfly_custom_loader_enabled').is(':checked');
            $('.custom-loader-setting').toggle(enabled);
        }

        $('#pixelfly_custom_loader_enabled').on('change', toggleCustomLoaderSettings);
        toggleCustomLoaderSettings();

        // Toggle consent settings visibility
        function toggleConsentSettings() {
            var enabled = $('#pixelfly_consent_enabled').is(':checked');
            $('.consent-setting').toggle(enabled);
        }

        $('#pixelfly_consent_enabled').on('change', toggleConsentSettings);
        toggleConsentSettings();

        // Test custom domain connection
        $('#pixelfly-test-custom-domain').on('click', function() {
            var $btn = $(this);
            var $result = $('#pixelfly-custom-domain-result');
            var domain = $('#pixelfly_custom_loader_domain').val();
            var gtmId = $('#pixelfly_gtm_container_id').val();

            if (!domain) {
                $result.removeClass('success').addClass('error').text('Please enter a custom domain first.');
                return;
            }

            if (!gtmId) {
                $result.removeClass('success').addClass('error').text('Please enter a GTM Container ID in DataLayer Settings first.');
                return;
            }

            $btn.prop('disabled', true);
            $result.removeClass('success error').text('Testing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'pixelfly_test_custom_domain',
                    domain: domain,
                    gtm_id: gtmId,
                    nonce: '<?php echo wp_create_nonce('pixelfly_test_custom_domain'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success').text(response.data.message);
                    } else {
                        $result.removeClass('success').addClass('error').text(response.data.message || 'Test failed.');
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error').text('Connection error. Please try again.');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // Update color code display when color picker changes
        $('input[type="color"]').on('input change', function() {
            $(this).next('code').text($(this).val());
        });
    });
</script>