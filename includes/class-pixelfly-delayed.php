<?php

/**
 * PixelFly Delayed Events Handler
 *
 * Handles delayed purchase events for COD/manual payment orders
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Delayed
{

    /**
     * Table name
     */
    private $table_name;

    /**
     * API client
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'pixelfly_pending_events';
        $this->api = new PixelFly_API();

        // Hook into order creation
        add_action('woocommerce_checkout_order_processed', [$this, 'maybe_store_pending_event'], 10, 3);

        // Hook into order status changes
        add_action('woocommerce_order_status_changed', [$this, 'maybe_fire_pending_event'], 10, 4);
    }

    /**
     * Check if delayed events are enabled for this order
     */
    public function is_enabled_for_order($order)
    {
        if (!get_option('pixelfly_delayed_enabled', true)) {
            return false;
        }

        $enabled_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        return in_array($order->get_payment_method(), $enabled_methods);
    }

    /**
     * Store pending event when order is created
     */
    public function maybe_store_pending_event($order_id, $posted_data, $order)
    {
        if (!$this->is_enabled_for_order($order)) {
            return;
        }

        // Build event data with full context
        $event_data = $this->build_purchase_event_data($order);

        // Store in database
        global $wpdb;
        $result = $wpdb->insert($this->table_name, [
            'order_id' => $order_id,
            'event_data' => wp_json_encode($event_data),
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ]);

        if ($result) {
            $order->update_meta_data('_pixelfly_pending_event_id', $wpdb->insert_id);
            $order->save();

            if (get_option('pixelfly_debug_mode', false)) {
                error_log('[PixelFly] Stored pending event for order #' . $order_id);
            }
        }
    }

    /**
     * Fire pending event when order status changes
     */
    public function maybe_fire_pending_event($order_id, $old_status, $new_status, $order)
    {
        $trigger_statuses = get_option('pixelfly_delayed_fire_on_status', ['processing', 'completed']);

        if (!in_array($new_status, $trigger_statuses)) {
            return;
        }

        // Get pending event
        global $wpdb;
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE order_id = %d AND status = 'pending'",
            $order_id
        ));

        if (!$pending) {
            return;
        }

        // Fire to PixelFly
        $event_data = json_decode($pending->event_data, true);

        // Update is_delayed flag
        $event_data['context']['is_delayed'] = true;
        $event_data['context']['delayed_reason'] = 'Order status changed to ' . $new_status;
        $event_data['context']['original_timestamp'] = $event_data['event_time'] ?? null;
        $event_data['event_time'] = time();

        $result = $this->api->send_event($event_data);

        // Update status
        $wpdb->update(
            $this->table_name,
            [
                'status' => $result ? 'fired' : 'failed',
                'fired_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $pending->id]
        );

        if ($result) {
            $order->update_meta_data('_pixelfly_server_tracked', true);
            $order->update_meta_data('_pixelfly_fired_at', current_time('mysql'));
            $order->save();

            if (get_option('pixelfly_debug_mode', false)) {
                error_log('[PixelFly] Fired delayed event for order #' . $order_id);
            }
        }
    }

    /**
     * Build purchase event data with full context
     */
    private function build_purchase_event_data($order)
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

            // Category
            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                $item_data['item_category'] = $categories[0]->name;
            }

            // Variant
            if ($product->is_type('variation')) {
                $attributes = $product->get_attributes();
                $variant_parts = [];
                foreach ($attributes as $key => $value) {
                    $variant_parts[] = $value;
                }
                if (!empty($variant_parts)) {
                    $item_data['item_variant'] = implode(' / ', $variant_parts);
                }
            }

            $items[] = $item_data;
            $item_ids[] = $item_data['item_id'];
        }

        // User data with Facebook IDs from cookies
        $user_data = [
            'fn' => $order->get_billing_first_name(),
            'ln' => $order->get_billing_last_name(),
            'em' => strtolower($order->get_billing_email()),
            'ph' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
            'ct' => strtolower($order->get_billing_city()),
            'st' => $order->get_billing_state(),
            'zp' => $order->get_billing_postcode(),
            'country' => strtoupper($order->get_billing_country()),
            'external_id' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
            'fbp' => isset($_COOKIE['_fbp']) ? sanitize_text_field($_COOKIE['_fbp']) : null,
            'fbc' => isset($_COOKIE['_fbc']) ? sanitize_text_field($_COOKIE['_fbc']) : null,
        ];

        // Filter empty values
        $user_data = array_filter($user_data);

        $event_time = time();

        return [
            'event' => 'purchase',
            'event_id' => 'purchase_' . $order->get_id() . '_' . $event_time,
            'event_time' => $event_time,
            'action_source' => 'website',
            'event_source_url' => $order->get_checkout_order_received_url(),
            'value' => (float) $order->get_subtotal(),
            'currency' => $order->get_currency(),
            'transaction_id' => (string) $order->get_id(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'coupon' => implode(', ', $order->get_coupon_codes()),
            'items' => $items,
            'content_ids' => $item_ids,
            'user_data' => $user_data,
            'context' => [
                'ip' => $order->get_customer_ip_address(),
                'user_agent' => $order->get_customer_user_agent(),
                'is_delayed' => true,
                'utm' => $this->get_utm_from_order_meta($order),
            ],
        ];
    }

    /**
     * Get UTM parameters from order meta
     */
    private function get_utm_from_order_meta($order)
    {
        $utm_fields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid'];
        $utm = [];

        foreach ($utm_fields as $field) {
            $value = $order->get_meta('_' . $field);
            if ($value) {
                $utm[$field] = $value;
            }
        }

        return array_filter($utm);
    }

    /**
     * Get pending events count
     */
    public static function get_pending_count()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'");
    }

    /**
     * Get all pending events
     */
    public static function get_pending_events($limit = 50, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT pe.*, o.post_status as order_status 
             FROM {$table_name} pe 
             LEFT JOIN {$wpdb->posts} o ON pe.order_id = o.ID 
             WHERE pe.status = 'pending' 
             ORDER BY pe.created_at DESC 
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    /**
     * Get event statistics
     */
    public static function get_stats()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';

        return [
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'"),
            'fired' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'fired'"),
            'failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'"),
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
        ];
    }

    /**
     * Manually fire a pending event
     */
    public static function fire_event($event_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';

        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d AND status = 'pending'",
            $event_id
        ));

        if (!$pending) {
            return false;
        }

        $api = new PixelFly_API();
        $event_data = json_decode($pending->event_data, true);
        $event_data['context']['is_delayed'] = true;
        $event_data['context']['manual_fire'] = true;
        $event_data['event_time'] = time();

        $result = $api->send_event($event_data);

        $wpdb->update(
            $table_name,
            [
                'status' => $result ? 'fired' : 'failed',
                'fired_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $pending->id]
        );

        return $result;
    }

    /**
     * Delete a pending event
     */
    public static function delete_event($event_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_pending_events';
        return $wpdb->delete($table_name, ['id' => $event_id]);
    }
}
