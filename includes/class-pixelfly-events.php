<?php

/**
 * PixelFly Events Builder
 *
 * Builds event data for products and orders
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_Events
{

    /**
     * Generate unique event ID
     *
     * @param string $prefix Optional prefix
     * @return string Event ID
     */
    public static function generate_event_id($prefix = 'event')
    {
        return $prefix . '_' . time() . '_' . wp_generate_password(8, false);
    }

    /**
     * Build product data for tracking
     *
     * @param WC_Product $product
     * @param int $quantity
     * @return array Product data
     */
    public static function build_product_data($product, $quantity = 1)
    {
        if (!$product) {
            return [];
        }

        $data = [
            'item_id' => (string) $product->get_id(),
            'item_name' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'quantity' => (int) $quantity,
        ];

        // Category
        $categories = get_the_terms($product->get_id(), 'product_cat');
        if ($categories && !is_wp_error($categories)) {
            $data['item_category'] = $categories[0]->name;

            // Add more category levels if available
            if (count($categories) > 1) {
                $data['item_category2'] = $categories[1]->name;
            }
        }

        // Brand (if taxonomy exists)
        if (taxonomy_exists('product_brand')) {
            $brands = get_the_terms($product->get_id(), 'product_brand');
            if ($brands && !is_wp_error($brands)) {
                $data['item_brand'] = $brands[0]->name;
            }
        }

        // Variant (for variable products)
        if ($product->is_type('variation')) {
            $attributes = $product->get_attributes();
            $variant_parts = [];
            foreach ($attributes as $key => $value) {
                $variant_parts[] = $value;
            }
            if (!empty($variant_parts)) {
                $data['item_variant'] = implode(' / ', $variant_parts);
            }
        }

        // SKU
        $sku = $product->get_sku();
        if ($sku) {
            $data['item_sku'] = $sku;
        }

        return $data;
    }

    /**
     * Build cart items data
     *
     * @return array Cart items and totals
     */
    public static function build_cart_data()
    {
        $cart = WC()->cart;
        if (!$cart) {
            return [];
        }

        $items = [];
        $item_ids = [];

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item_data = self::build_product_data($product, $cart_item['quantity']);
            $items[] = $item_data;
            $item_ids[] = $item_data['item_id'];
        }

        return [
            'currency' => get_woocommerce_currency(),
            'value' => (float) $cart->get_subtotal(),
            'items' => $items,
            'content_ids' => $item_ids,
            'num_items' => $cart->get_cart_contents_count(),
        ];
    }

    /**
     * Build order data for purchase event
     *
     * @param WC_Order $order
     * @return array Complete purchase event data
     */
    public static function build_purchase_data($order)
    {
        if (!$order) {
            return [];
        }

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

        $event_id = self::generate_event_id('purchase_' . $order->get_id());

        return [
            'event_id' => $event_id,
            'ecommerce' => [
                'currency' => $order->get_currency(),
                'value' => (float) $order->get_subtotal(),
                'tax' => (float) $order->get_total_tax(),
                'shipping' => (float) $order->get_shipping_total(),
                'transaction_id' => (string) $order->get_id(),
                'coupon' => implode(', ', $order->get_coupon_codes()),
                'items' => $items,
            ],
            'content_ids' => $item_ids,
            'user_data' => PixelFly_User_Data::get_user_data_from_order($order),
        ];
    }

    /**
     * Build view_item_list data
     *
     * @param array $products Array of WC_Product objects
     * @param string $list_name Name of the product list
     * @return array
     */
    public static function build_item_list_data($products, $list_name = '')
    {
        $items = [];
        $position = 0;

        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            $item_data = self::build_product_data($product);
            $item_data['index'] = $position++;

            if ($list_name) {
                $item_data['item_list_name'] = $list_name;
            }

            $items[] = $item_data;
        }

        return [
            'currency' => get_woocommerce_currency(),
            'items' => $items,
            'item_list_name' => $list_name,
        ];
    }
}
