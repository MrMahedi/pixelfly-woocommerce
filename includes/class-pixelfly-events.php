<?php

/**
 * PixelFly Events Builder
 *
 * Builds comprehensive event data for products and orders
 * Compatible with GA4 and Meta CAPI requirements
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

        $product_id = $product->get_id();

        $data = [
            'item_id' => (string) $product_id,
            'id' => (string) $product_id,
            'item_name' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'quantity' => (int) $quantity,
        ];

        // SKU
        $sku = $product->get_sku();
        $data['sku'] = $sku ? $sku : (string) $product_id;

        // Stock info
        $data['stockstatus'] = $product->get_stock_status();
        $data['stocklevel'] = $product->get_stock_quantity();

        // Google Business Vertical for remarketing
        $data['google_business_vertical'] = 'retail';

        // Category hierarchy (up to 5 levels)
        $categories = get_the_terms($product_id, 'product_cat');
        if ($categories && !is_wp_error($categories)) {
            // Sort by parent to get hierarchy
            usort($categories, function ($a, $b) {
                return $a->parent - $b->parent;
            });

            $cat_index = 1;
            foreach ($categories as $category) {
                if ($cat_index === 1) {
                    $data['item_category'] = $category->name;
                } else {
                    $data['item_category' . $cat_index] = $category->name;
                }
                $cat_index++;
                if ($cat_index > 5) break;
            }
        }

        // Brand (if taxonomy exists)
        if (taxonomy_exists('product_brand')) {
            $brands = get_the_terms($product_id, 'product_brand');
            if ($brands && !is_wp_error($brands)) {
                $data['item_brand'] = $brands[0]->name;
            }
        }

        // Variant and item_group_id (for variable products)
        if ($product->is_type('variation')) {
            $data['item_group_id'] = (string) $product->get_parent_id();
            $attributes = $product->get_attributes();
            $variant_parts = [];
            foreach ($attributes as $key => $value) {
                $variant_parts[] = $value;
            }
            if (!empty($variant_parts)) {
                $data['item_variant'] = implode(' / ', $variant_parts);
            }
        } elseif ($product->is_type('variable')) {
            $data['item_group_id'] = (string) $product_id;
        }

        return $data;
    }

    /**
     * Build cart content data (full structure)
     *
     * @return array Cart content with totals and items
     */
    public static function build_cart_content()
    {
        $cart = WC()->cart;
        if (!$cart) {
            return [
                'totals' => [
                    'applied_coupons' => [],
                    'discount_total' => 0,
                    'subtotal' => 0,
                    'total' => 0,
                ],
                'items' => [],
            ];
        }

        $items = [];
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if ($product) {
                $items[] = self::build_product_data($product, $cart_item['quantity']);
            }
        }

        return [
            'totals' => [
                'applied_coupons' => $cart->get_applied_coupons(),
                'discount_total' => (float) $cart->get_discount_total(),
                'subtotal' => (float) $cart->get_subtotal(),
                'total' => (float) $cart->get_total('edit'),
            ],
            'items' => $items,
        ];
    }

    /**
     * Build cart items data (for ecommerce events)
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
     * Build comprehensive order data for purchase event
     *
     * @param WC_Order $order
     * @return array Complete purchase event data with orderData
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
                'id' => (string) $product->get_id(),
                'item_name' => $product->get_name(),
                'sku' => $product->get_sku() ?: (string) $product->get_id(),
                'price' => (float) $order->get_item_total($item, false),
                'quantity' => (int) $item->get_quantity(),
                'stocklevel' => $product->get_stock_quantity(),
                'stockstatus' => $product->get_stock_status(),
                'google_business_vertical' => 'retail',
            ];

            // Category
            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                $item_data['item_category'] = $categories[0]->name;
            }

            // Variant
            if ($product->is_type('variation')) {
                $item_data['item_group_id'] = (string) $product->get_parent_id();
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

        // Get shipping method
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method = '';
        if (!empty($shipping_methods)) {
            $first_shipping = reset($shipping_methods);
            $shipping_method = $first_shipping->get_method_title();
        }

        // Build orderData structure
        $order_data = [
            'attributes' => [
                'date' => $order->get_date_created() ? $order->get_date_created()->format('c') : '',
                'order_number' => (int) $order->get_order_number(),
                'order_key' => $order->get_order_key(),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'shipping_method' => $shipping_method,
                'status' => $order->get_status(),
                'coupons' => implode(', ', $order->get_coupon_codes()),
            ],
            'totals' => [
                'currency' => $order->get_currency(),
                'discount_total' => (float) $order->get_discount_total(),
                'discount_tax' => (float) $order->get_discount_tax(),
                'shipping_total' => (float) $order->get_shipping_total(),
                'shipping_tax' => (float) $order->get_shipping_tax(),
                'cart_tax' => (float) $order->get_cart_tax(),
                'total' => (float) $order->get_total(),
                'total_tax' => (float) $order->get_total_tax(),
                'total_discount' => (float) $order->get_total_discount(),
                'subtotal' => (float) $order->get_subtotal(),
                'tax_totals' => $order->get_tax_totals(),
            ],
            'customer' => self::build_order_customer_data($order),
            'items' => $items,
        ];

        return [
            'event_id' => $event_id,
            'ecommerce' => [
                'currency' => $order->get_currency(),
                'transaction_id' => (string) $order->get_id(),
                'affiliation' => get_bloginfo('name'),
                'value' => (float) $order->get_total(),
                'tax' => (float) $order->get_total_tax(),
                'shipping' => (float) $order->get_shipping_total(),
                'coupon' => implode(', ', $order->get_coupon_codes()),
                'items' => $items,
            ],
            'orderData' => $order_data,
            'content_ids' => $item_ids,
            'user_data' => PixelFly_User_Data::get_user_data_from_order($order),
        ];
    }

    /**
     * Build customer data from order
     *
     * @param WC_Order $order
     * @return array Customer data with billing and shipping
     */
    public static function build_order_customer_data($order)
    {
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();
        $billing_first = $order->get_billing_first_name();
        $billing_last = $order->get_billing_last_name();

        return [
            'id' => $order->get_customer_id(),
            'billing' => [
                'first_name' => $billing_first,
                'first_name_hash' => $billing_first ? hash('sha256', strtolower(trim($billing_first))) : '',
                'last_name' => $billing_last,
                'last_name_hash' => $billing_last ? hash('sha256', strtolower(trim($billing_last))) : '',
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $billing_email,
                'emailhash' => $billing_email ? hash('sha256', strtolower(trim($billing_email))) : '',
                'email_hash' => $billing_email ? hash('sha256', strtolower(trim($billing_email))) : '',
                'phone' => $billing_phone,
                'phone_hash' => $billing_phone ? hash('sha256', preg_replace('/[^0-9]/', '', $billing_phone)) : '',
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ],
        ];
    }

    /**
     * Build customer data for logged-in user
     *
     * @return array Customer data
     */
    public static function build_customer_data()
    {
        if (!is_user_logged_in()) {
            return [];
        }

        $user_id = get_current_user_id();
        $customer = new WC_Customer($user_id);

        // Get order history
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => -1,
        ]);

        $total_orders = count($orders);
        $total_value = 0;
        foreach ($orders as $order) {
            $total_value += (float) $order->get_total();
        }

        $billing_email = $customer->get_billing_email();
        $billing_phone = $customer->get_billing_phone();

        return [
            'customerTotalOrders' => $total_orders,
            'customerTotalOrderValue' => round($total_value, 2),
            'customerFirstName' => $customer->get_first_name(),
            'customerLastName' => $customer->get_last_name(),
            'customerBillingFirstName' => $customer->get_billing_first_name(),
            'customerBillingLastName' => $customer->get_billing_last_name(),
            'customerBillingCompany' => $customer->get_billing_company(),
            'customerBillingAddress1' => $customer->get_billing_address_1(),
            'customerBillingAddress2' => $customer->get_billing_address_2(),
            'customerBillingCity' => $customer->get_billing_city(),
            'customerBillingState' => $customer->get_billing_state(),
            'customerBillingPostcode' => $customer->get_billing_postcode(),
            'customerBillingCountry' => $customer->get_billing_country(),
            'customerBillingEmail' => $billing_email,
            'customerBillingEmailHash' => $billing_email ? hash('sha256', strtolower(trim($billing_email))) : '',
            'customerBillingPhone' => $billing_phone,
            'customerShippingFirstName' => $customer->get_shipping_first_name(),
            'customerShippingLastName' => $customer->get_shipping_last_name(),
            'customerShippingCompany' => $customer->get_shipping_company(),
            'customerShippingAddress1' => $customer->get_shipping_address_1(),
            'customerShippingAddress2' => $customer->get_shipping_address_2(),
            'customerShippingCity' => $customer->get_shipping_city(),
            'customerShippingState' => $customer->get_shipping_state(),
            'customerShippingPostcode' => $customer->get_shipping_postcode(),
            'customerShippingCountry' => $customer->get_shipping_country(),
        ];
    }

    /**
     * Get page post type info
     *
     * @return array Page type data
     */
    public static function get_page_info()
    {
        global $post;

        $data = [
            'pagePostType' => '',
            'pagePostType2' => '',
            'pagePostAuthor' => '',
        ];

        if ($post) {
            $data['pagePostType'] = $post->post_type;
            $data['pagePostType2'] = (is_singular() ? 'single-' : 'archive-') . $post->post_type;

            $author = get_userdata($post->post_author);
            if ($author) {
                $data['pagePostAuthor'] = $author->display_name;
            }
        }

        return $data;
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
