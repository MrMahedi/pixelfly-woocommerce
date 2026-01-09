<?php

/**
 * PixelFly Tracker
 *
 * Handles server-side tracking and AJAX events
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Tracker
{

    /**
     * API client
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->api = new PixelFly_API();

        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX handlers
        add_action('wp_ajax_pixelfly_get_product_data', [$this, 'ajax_get_product_data']);
        add_action('wp_ajax_nopriv_pixelfly_get_product_data', [$this, 'ajax_get_product_data']);

        add_action('wp_ajax_pixelfly_get_cart_data', [$this, 'ajax_get_cart_data']);
        add_action('wp_ajax_nopriv_pixelfly_get_cart_data', [$this, 'ajax_get_cart_data']);

        add_action('wp_ajax_pixelfly_track_event', [$this, 'ajax_track_event']);
        add_action('wp_ajax_nopriv_pixelfly_track_event', [$this, 'ajax_track_event']);

        // Server-side tracking for immediate events
        add_action('woocommerce_thankyou', [$this, 'server_side_purchase'], 20, 1);
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts()
    {
        if (!function_exists('is_woocommerce')) {
            return;
        }

        wp_enqueue_script(
            'pixelfly-tracker',
            PIXELFLY_WC_PLUGIN_URL . 'public/js/pixelfly-tracker.js',
            ['jquery'],
            PIXELFLY_WC_VERSION,
            true
        );

        wp_localize_script('pixelfly-tracker', 'pixelflyConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pixelfly_nonce'),
            'currency' => get_woocommerce_currency(),
            'debug' => get_option('pixelfly_debug_mode', false),
        ]);
    }

    /**
     * AJAX: Get product data
     */
    public function ajax_get_product_data()
    {
        check_ajax_referer('pixelfly_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product ID']);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found']);
        }

        $product_data = PixelFly_Events::build_product_data($product);
        wp_send_json_success($product_data);
    }

    /**
     * AJAX: Get cart data
     */
    public function ajax_get_cart_data()
    {
        check_ajax_referer('pixelfly_nonce', 'nonce');

        $cart_data = PixelFly_Events::build_cart_data();
        wp_send_json_success($cart_data);
    }

    /**
     * AJAX: Track custom event
     */
    public function ajax_track_event()
    {
        check_ajax_referer('pixelfly_nonce', 'nonce');

        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $event_data = isset($_POST['event_data']) ? $_POST['event_data'] : [];

        if (!$event_type) {
            wp_send_json_error(['message' => 'Event type required']);
        }

        // Build event payload
        $payload = [
            'event' => $event_type,
            'event_id' => sanitize_text_field($event_data['event_id'] ?? PixelFly_Events::generate_event_id()),
            'value' => isset($event_data['value']) ? (float) $event_data['value'] : 0,
            'currency' => get_woocommerce_currency(),
            'user_data' => PixelFly_User_Data::get_user_data(),
        ];

        // Add ecommerce data if present
        if (!empty($event_data['items'])) {
            $payload['items'] = $event_data['items'];
            $payload['content_ids'] = array_column($event_data['items'], 'item_id');
        }

        $result = $this->api->send_event($payload);

        if ($result) {
            wp_send_json_success(['message' => 'Event tracked', 'event_id' => $payload['event_id']]);
        } else {
            wp_send_json_error(['message' => 'Failed to track event']);
        }
    }

    /**
     * Server-side purchase tracking
     */
    public function server_side_purchase($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if already sent to server
        if ($order->get_meta('_pixelfly_server_tracked')) {
            return;
        }

        // Check if delayed events are enabled for this payment method
        $delayed_enabled = get_option('pixelfly_delayed_enabled', true);
        $delayed_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        $payment_method = $order->get_payment_method();

        if ($delayed_enabled && in_array($payment_method, $delayed_methods)) {
            // Will be handled by delayed system
            return;
        }

        // Send server-side event
        $purchase_data = $this->build_server_purchase_data($order);
        $result = $this->api->send_event($purchase_data);

        if ($result) {
            $order->update_meta_data('_pixelfly_server_tracked', true);
            $order->save();
        }
    }

    /**
     * Build server-side purchase data
     */
    private function build_server_purchase_data($order)
    {
        $items = [];
        $item_ids = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $item_data = [
                'item_id' => (string) $product->get_id(),
                'item_name' => $product->get_name(),
                'price' => (float) $order->get_item_total($item, false),
                'quantity' => (int) $item->get_quantity(),
            ];

            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                $item_data['item_category'] = $categories[0]->name;
            }

            $items[] = $item_data;
            $item_ids[] = $item_data['item_id'];
        }

        return [
            'event' => 'purchase',
            'event_id' => 'purchase_' . $order->get_id() . '_' . time(),
            'value' => (float) $order->get_subtotal(),
            'currency' => $order->get_currency(),
            'transaction_id' => (string) $order->get_id(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'coupon' => implode(', ', $order->get_coupon_codes()),
            'items' => $items,
            'content_ids' => $item_ids,
            'user_data' => PixelFly_User_Data::get_user_data_from_order($order),
            'context' => [
                'ip' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
            ],
            'event_source_url' => $order->get_checkout_order_received_url(),
        ];
    }
}
