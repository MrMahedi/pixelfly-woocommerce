<?php

/**
 * PixelFly COD Order Protection (Laravel held_events + /cod/hold).
 *
 * Master switch: pixelfly_cod_enabled (on/off).
 * Modes (only when enabled):
 * - legacy: old plugin DB delayed events (PixelFly_Delayed)
 * - gtm: dataLayer purchase + payment_method for GTM/sGTM hold (recommended)
 * - plugin_hold: PHP POST /cod/hold from WooCommerce (no GTM required)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_COD_Protection
{
    public const MODE_LEGACY = 'legacy';
    public const MODE_GTM = 'gtm';
    public const MODE_PLUGIN_HOLD = 'plugin_hold';

    public function __construct()
    {
        add_action('woocommerce_checkout_order_processed', [$this, 'maybe_hold_order'], 20, 3);
        add_action('woocommerce_order_status_changed', [$this, 'maybe_webhook_confirm'], 20, 4);
    }

    /**
     * Master On/Off for COD Order Protection / delayed purchase.
     * When off: purchases fire immediately (no hold, no webhook, no legacy delay).
     */
    public static function is_enabled(): bool
    {
        $stored = get_option('pixelfly_cod_enabled', null);
        if ($stored === null) {
            // Back-compat before the On/Off control existed.
            $mode = get_option('pixelfly_cod_mode', self::MODE_LEGACY);
            if (in_array($mode, [self::MODE_GTM, self::MODE_PLUGIN_HOLD], true)) {
                return true;
            }

            return (bool) get_option('pixelfly_delayed_enabled', true);
        }

        return (bool) $stored;
    }

    public static function get_mode(): string
    {
        $mode = get_option('pixelfly_cod_mode', self::MODE_LEGACY);
        $allowed = [self::MODE_LEGACY, self::MODE_GTM, self::MODE_PLUGIN_HOLD];
        return in_array($mode, $allowed, true) ? $mode : self::MODE_LEGACY;
    }

    public static function uses_cod_protection(): bool
    {
        if (!self::is_enabled()) {
            return false;
        }

        return in_array(self::get_mode(), [self::MODE_GTM, self::MODE_PLUGIN_HOLD], true);
    }

    public static function is_legacy_delayed(): bool
    {
        if (!self::is_enabled()) {
            return false;
        }

        return self::get_mode() === self::MODE_LEGACY && get_option('pixelfly_delayed_enabled', true);
    }

    /**
     * Skip dataLayer purchase for COD (legacy delayed only).
     */
    public static function should_skip_datalayer_purchase(string $payment_method): bool
    {
        if (!self::is_legacy_delayed()) {
            return false;
        }

        $methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        return in_array($payment_method, (array) $methods, true);
    }

    public static function is_cod_order($order): bool
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $methods = get_option('pixelfly_cod_payment_methods', null);
        if ($methods === null) {
            $methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        }

        return in_array($order->get_payment_method(), (array) $methods, true);
    }

    public static function hold_url(): string
    {
        $custom = trim((string) get_option('pixelfly_cod_hold_url', ''));
        if ($custom !== '') {
            $custom = preg_replace('#/cod/hold/?$#', '', rtrim($custom, '/'));
            return 'https://' . ltrim(preg_replace('#^https?://#', '', $custom), '/') . '/cod/hold';
        }

        $domain = trim((string) get_option('pixelfly_custom_loader_domain', ''));
        if ($domain !== '') {
            return 'https://' . preg_replace('#^https?://#', '', rtrim($domain, '/')) . '/cod/hold';
        }

        $endpoint = get_option('pixelfly_endpoint', 'https://track.pixelfly.io/e');
        $host = wp_parse_url($endpoint, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return 'https://' . $host . '/cod/hold';
        }

        return 'https://track.pixelfly.io/cod/hold';
    }

    public static function webhook_url(): string
    {
        return preg_replace('#/cod/hold$#', '/cod/webhook', self::hold_url());
    }

    /**
     * Build /cod/hold JSON body from WooCommerce order.
     */
    public static function build_hold_payload(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $row = [
                'item_id' => (string) $product->get_id(),
                'item_name' => $product->get_name(),
                'price' => (float) $order->get_item_total($item, false),
                'quantity' => (int) $item->get_quantity(),
            ];
            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                $row['item_category'] = $categories[0]->name;
            }
            $items[] = $row;
        }

        $utm = self::get_utm_from_order($order);
        $event_time = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

        $payload = [
            'order_id' => (string) $order->get_id(),
            'event_id' => 'purchase_' . $order->get_id(),
            'event_name' => 'Purchase',
            'event_time' => $event_time,
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'email' => strtolower($order->get_billing_email()),
            'phone' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'fbp' => isset($_COOKIE['_fbp']) ? sanitize_text_field(wp_unslash($_COOKIE['_fbp'])) : null,
            'fbc' => isset($_COOKIE['_fbc']) ? sanitize_text_field(wp_unslash($_COOKIE['_fbc'])) : null,
            'client_ip' => $order->get_customer_ip_address(),
            'user_agent' => $order->get_customer_user_agent(),
            'event_source_url' => $order->get_checkout_order_received_url(),
            'contents' => $items,
            'source' => 'woocommerce_plugin',
        ];

        if (!empty($utm['gclid'])) {
            $payload['gclid'] = $utm['gclid'];
        }
        if (!empty($utm['fbclid'])) {
            $payload['fbclid'] = $utm['fbclid'];
        }
        if (!empty($utm['ttclid'])) {
            $payload['ttclid'] = $utm['ttclid'];
        }

        return array_filter($payload, static function ($v) {
            return $v !== null && $v !== '';
        });
    }

    /**
     * POST hold to PixelFly (plugin_hold mode, or GTM backup).
     */
    public function maybe_hold_order($order_id, $posted_data, $order): void
    {
        if (!self::is_enabled()) {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || !self::is_cod_order($order)) {
            return;
        }

        $mode = self::get_mode();
        $should_hold = ($mode === self::MODE_PLUGIN_HOLD)
            || ($mode === self::MODE_GTM && get_option('pixelfly_cod_server_hold_backup', false));

        if (!$should_hold) {
            return;
        }

        if ($order->get_meta('_pixelfly_cod_held')) {
            return;
        }

        $result = self::send_hold($order);
        if ($result) {
            $order->update_meta_data('_pixelfly_cod_held', '1');
            $order->update_meta_data('_pixelfly_cod_held_at', current_time('mysql'));
            $order->save();
        }
    }

    public static function send_hold(WC_Order $order): bool
    {
        $api_key = get_option('pixelfly_api_key', '');
        if ($api_key === '') {
            return false;
        }

        $payload = self::build_hold_payload($order);
        $response = wp_remote_post(self::hold_url(), [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PF-Key' => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            self::debug_log('COD hold failed: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            self::debug_log('COD hold OK for order #' . $order->get_id());
            return true;
        }

        self::debug_log('COD hold HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        return false;
    }

    /**
     * Optional: tell PixelFly to confirm/fire when Woo order reaches a status.
     */
    public function maybe_webhook_confirm($order_id, $old_status, $new_status, $order): void
    {
        if (!self::uses_cod_protection()) {
            return;
        }

        if (!get_option('pixelfly_cod_webhook_enabled', false)) {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || !self::is_cod_order($order)) {
            return;
        }

        $trigger = get_option('pixelfly_cod_webhook_statuses', ['processing', 'completed']);
        if (!in_array($new_status, (array) $trigger, true)) {
            return;
        }

        if ($order->get_meta('_pixelfly_cod_webhook_sent')) {
            return;
        }

        if (self::send_webhook($order, $new_status)) {
            $order->update_meta_data('_pixelfly_cod_webhook_sent', '1');
            $order->save();
        }
    }

    public static function send_webhook(WC_Order $order, string $status): bool
    {
        $secret = trim((string) get_option('pixelfly_cod_webhook_secret', ''));
        if ($secret === '') {
            return false;
        }

        $body = [
            'type' => 'order.status',
            'order_id' => (string) $order->get_id(),
            'status' => $status,
        ];

        $response = wp_remote_post(self::webhook_url(), [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-Secret' => $secret,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            self::debug_log('COD webhook failed: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    private static function get_utm_from_order(WC_Order $order): array
    {
        $fields = ['utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'gclid', 'ttclid', 'msclkid'];
        $utm = [];
        foreach ($fields as $field) {
            $value = $order->get_meta('_' . $field);
            if ($value) {
                $utm[$field] = $value;
            }
        }
        return $utm;
    }

    private static function debug_log(string $message): void
    {
        if (get_option('pixelfly_debug_mode', false)) {
            error_log('[PixelFly COD] ' . $message);
        }
    }
}
