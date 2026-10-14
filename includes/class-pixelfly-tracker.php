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

        // Server-side purchase: every website order is sent from WooCommerce itself once
        // it is placed, because the browser purchase cannot be relied on — the Facebook
        // app's browser on Android never sends it. The status hook catches an order whose
        // shopper never sees the thank-you page (gateway redirect lost, tab closed); the
        // thank-you page is the fallback. Priority 30: after COD Protection (20).
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 30, 4);
        add_action('woocommerce_thankyou', [$this, 'server_side_purchase'], 5, 1);

        // The send itself runs in the background, so neither checkout nor the
        // order-received page ever waits on it.
        add_action('pixelfly_send_server_purchase', [$this, 'send_server_purchase_async'], 10, 1);
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts()
    {
        // This script pushes cart events into the dataLayer. With PixelFly's DataLayer off
        // another plugin owns the dataLayer (e.g. GTM4WP), which pushes them already:
        // loading it there would fire every add_to_cart twice.
        if (!function_exists('is_woocommerce') || !get_option('pixelfly_datalayer_enabled', true)) {
            return;
        }

        wp_enqueue_script(
            'pixelfly-tracker',
            PIXELFLY_PLUGIN_URL . 'public/js/pixelfly-tracker.js',
            ['jquery'],
            PIXELFLY_VERSION,
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

        $event_type = isset($_POST['event_type']) ? sanitize_text_field(wp_unslash($_POST['event_type'])) : '';
        $event_data = isset($_POST['event_data']) ? wp_unslash($_POST['event_data']) : [];

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
     * Order statuses that mean the order is placed: never an unpaid "pending" one, a
     * failed payment or a cancellation. The same set GTM4WP tracks by default.
     */
    const SERVER_PURCHASE_STATUSES = ['processing', 'on-hold', 'completed'];

    /**
     * Orders not made on the website: typed in by staff (a phone or Messenger order),
     * created over the REST API, or a subscription renewal. No shopper visit to match.
     */
    const SERVER_PURCHASE_EXCLUDED_SOURCES = ['admin', 'rest-api', 'subscription'];

    /**
     * Older orders are never sent, so a status change on last month's order (or on one
     * placed before this plugin version) does not show up as a new sale. 3 days.
     */
    const SERVER_PURCHASE_MAX_AGE = 259200;

    /** Seconds before each retry of a failed send. */
    const SERVER_PURCHASE_RETRIES = [60, 300, 1800];

    /**
     * Seconds after the order before the server copy goes out. By then a working browser
     * has sent its own purchase, and PixelFly drops the copy (pf_server_purchase) instead
     * of duplicating the order in GA4 or Meta; a browser that never sent it — the Facebook
     * app on Android — leaves the copy to count. Filter: pixelfly_server_purchase_delay.
     */
    const SERVER_PURCHASE_DELAY = 300;

    /**
     * Whether this order still needs its server purchase.
     *
     * @param WC_Order|false|null $order
     * @return bool
     */
    public function should_send_server_purchase($order)
    {
        if (!$order instanceof WC_Order || !get_option('pixelfly_server_purchase_enabled', true)) {
            return false;
        }
        if ($order->get_meta('_pixelfly_server_tracked')) {
            return false;
        }

        $statuses = (array) apply_filters('pixelfly_server_purchase_statuses', self::SERVER_PURCHASE_STATUSES, $order);
        if (!in_array($order->get_status(), $statuses, true)) {
            return false;
        }

        $excluded = (array) apply_filters('pixelfly_server_purchase_excluded_sources', self::SERVER_PURCHASE_EXCLUDED_SOURCES, $order);
        if (in_array($order->get_created_via(), $excluded, true)) {
            return false;
        }

        $created = $order->get_date_created();
        if (!$created || $created->getTimestamp() < time() - self::SERVER_PURCHASE_MAX_AGE) {
            return false;
        }

        // COD orders belong to Order Protection / legacy delayed, which fire the purchase
        // when the order is confirmed. Sending it now would count an unconfirmed order.
        if (PixelFly_COD_Protection::should_skip_datalayer_purchase($order->get_payment_method())
            || (PixelFly_COD_Protection::uses_cod_protection() && PixelFly_COD_Protection::is_cod_order($order))) {
            return false;
        }

        return true;
    }

    /**
     * woocommerce_order_status_changed: the order was just placed or paid.
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order = null)
    {
        $this->maybe_schedule_server_purchase($order instanceof WC_Order ? $order : wc_get_order($order_id));
    }

    /**
     * woocommerce_thankyou: fallback for a store whose gateway placed the order without
     * a status change this plugin saw.
     */
    public function server_side_purchase($order_id)
    {
        $this->maybe_schedule_server_purchase(wc_get_order($order_id));
    }

    /**
     * @param WC_Order|false|null $order
     */
    private function maybe_schedule_server_purchase($order)
    {
        if ($this->should_send_server_purchase($order)) {
            $delay = (int) apply_filters('pixelfly_server_purchase_delay', self::SERVER_PURCHASE_DELAY, $order);
            $this->schedule_server_purchase($order->get_id(), max(0, $delay));
        }
    }

    /**
     * Queue the send. Action Scheduler (bundled with WooCommerce) runs it within seconds
     * through its own async request; WP-Cron is the fallback.
     *
     * @param int $order_id
     * @param int $delay Seconds from now
     * @param bool $is_retry A retry is queued from inside the running send, which would
     *                       otherwise count as "already scheduled".
     */
    private function schedule_server_purchase($order_id, $delay = 0, $is_retry = false)
    {
        $args = [(int) $order_id];

        if (function_exists('as_enqueue_async_action') && function_exists('as_schedule_single_action')) {
            if (!$is_retry && function_exists('as_has_scheduled_action')
                && as_has_scheduled_action('pixelfly_send_server_purchase', $args, 'pixelfly')) {
                return;
            }
            if ($delay > 0) {
                as_schedule_single_action(time() + $delay, 'pixelfly_send_server_purchase', $args, 'pixelfly');
            } else {
                as_enqueue_async_action('pixelfly_send_server_purchase', $args, 'pixelfly');
            }
            return;
        }

        if (!wp_next_scheduled('pixelfly_send_server_purchase', $args)) {
            wp_schedule_single_event(time() + $delay, 'pixelfly_send_server_purchase', $args);
            if ($delay === 0 && function_exists('spawn_cron')) {
                spawn_cron();
            }
        }
    }

    /**
     * Background send. Re-checks the order first: it may have been cancelled, or sent
     * already, since it was queued. A failed send is retried (SERVER_PURCHASE_RETRIES).
     *
     * The event_id is purchase_{order_id} on every channel, so the browser purchase and
     * this one are one event to Meta, one transaction to GA4, and one order to PixelFly.
     *
     * @param int $order_id
     */
    public function send_server_purchase_async($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$this->should_send_server_purchase($order)) {
            return;
        }

        $route = PixelFly_API::server_purchase_route();
        if ($this->api->send_server_purchase($this->build_server_purchase_data($order), 10)) {
            $order->update_meta_data('_pixelfly_server_tracked', true);
            $order->update_meta_data('_pixelfly_server_tracked_via', $route);
            $order->update_meta_data('_pixelfly_server_tracked_at', current_time('mysql'));
            $order->delete_meta_data('_pixelfly_server_attempts');
            $order->save();
            return;
        }

        $attempts = (int) $order->get_meta('_pixelfly_server_attempts') + 1;
        $order->update_meta_data('_pixelfly_server_attempts', $attempts);
        $order->save();

        if ($attempts <= count(self::SERVER_PURCHASE_RETRIES)) {
            $this->schedule_server_purchase($order->get_id(), self::SERVER_PURCHASE_RETRIES[$attempts - 1], true);
        } elseif (get_option('pixelfly_debug_mode', false)) {
            error_log('[PixelFly] Server purchase for order #' . $order->get_id() . ' failed after ' . $attempts . ' attempts (' . $route . ')');
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

        $browser = PixelFly_User_Data::get_browser_ids_for_order($order);

        // When the order happened — the send itself runs minutes later.
        $created = $order->get_date_created();
        $event_time = $created ? $created->getTimestamp() : time();

        return [
            'event' => 'purchase',
            'event_time' => $event_time,
            'timestamp' => $event_time,
            'action_source' => 'website',
            // Deterministic — must match the dataLayer/browser purchase and the COD
            // hold payload so Meta can deduplicate the same order across channels.
            'event_id' => PixelFly_Events::get_purchase_event_id($order->get_id()),
            // Order total, not subtotal — subtotal excludes tax/shipping and ignores
            // discounts, so it never matches what the browser purchase reports.
            'value' => PixelFly_Events::get_purchase_value($order),
            'currency' => $order->get_currency(),
            'transaction_id' => (string) $order->get_id(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'coupon' => implode(', ', $order->get_coupon_codes()),
            'items' => $items,
            'content_ids' => $item_ids,
            // Meta-shaped contents (id/quantity/item_price) so Meta CAPI can validate
            // the purchase value against line items — content_ids alone carries no price.
            'contents' => PixelFly_Events::build_meta_contents($items),
            // _fbp / _fbc / GA ids from the order: this runs in the background, without
            // the shopper's cookies. client_id / session_id are the names the PixelFly
            // proxy's GA4 Receiver reads.
            'user_data' => array_merge(
                PixelFly_User_Data::get_user_data_from_order($order),
                $browser,
                array_filter([
                    'client_id' => $browser['ga_client_id'] ?? null,
                    'session_id' => $browser['ga_session_id'] ?? null,
                ])
            ),
            // Marks the copy PixelFly may drop when the browser's purchase already arrived.
            'pf_server_purchase' => true,
            // COD Order Protection's tags pause Meta/Google and hold the order by
            // payment_method; PixelFly also never sends a COD copy for a container that
            // holds COD orders, or for an order already held.
            'payment_method' => $order->get_payment_method(),
            'pf_cod_order' => PixelFly_COD_Protection::is_cod_order($order),
            'context' => [
                'ip' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
                'utm' => PixelFly_UTM_Capture::get_attribution_for_order($order),
                'is_delayed' => false,
            ],
            'event_source_url' => $order->get_checkout_order_received_url(),
        ];
    }
}
