<?php

/**
 * PixelFly DataLayer Output
 *
 * Outputs dataLayer events for GTM integration
 * Compatible with all themes including Astra, Elementor, GeneratePress, OceanWP,
 * WooCommerce Blocks, and classic checkout
 *
 * Architecture inspired by Stape.io's GTM Server Side plugin for maximum compatibility
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_DataLayer
{
    /**
     * Cookie name for purchase tracking (like Stape's TRANSACTION_KEY)
     */
    const PURCHASE_COOKIE_KEY = 'pixelfly_order_id';

    /**
     * Excluded user roles
     */
    private $excluded_roles = [];

    /**
     * Track if events have been fired to prevent duplicates
     * Public so PixelFly_Tracker can set flags before dataLayer runs
     */
    public static $fired_events = [];

    /**
     * Instance flag for order creation (like Stape's $is_order_created)
     */
    private $is_order_created = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        if (!get_option('pixelfly_datalayer_enabled', true)) {
            return;
        }

        // Check user role exclusion
        $this->excluded_roles = get_option('pixelfly_excluded_roles', []);
        if ($this->is_user_excluded()) {
            return;
        }

        // Inject GTM script in head (priority 1 - before other scripts)
        add_action('wp_head', [$this, 'inject_gtm_head'], 1);

        // Initialize dataLayer on every page
        add_action('wp_head', [$this, 'init_datalayer'], 2);

        // Inject GTM noscript in body (multiple hooks for theme compatibility)
        add_action('wp_body_open', [$this, 'inject_gtm_body'], 1);
        add_action('genesis_before', [$this, 'inject_gtm_body'], 1);
        add_action('body_open', [$this, 'inject_gtm_body'], 1);
        add_action('generate_before_header', [$this, 'inject_gtm_body'], 1);
        add_action('astra_body_top', [$this, 'inject_gtm_body'], 1);

        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // ============================================================
        // ALL DATALAYER EVENTS FIRE IN WP_FOOTER FOR THEME COMPATIBILITY
        // This is the Stape.io pattern - works with all themes
        // ============================================================
        add_action('wp_footer', [$this, 'view_item_event_footer']);
        add_action('wp_footer', [$this, 'view_item_list_event_footer']);
        add_action('wp_footer', [$this, 'view_cart_event_footer']);
        add_action('wp_footer', [$this, 'begin_checkout_event_footer']);
        add_action('wp_footer', [$this, 'purchase_event_footer']);
        add_action('wp_footer', [$this, 'blocks_compatibility_script']);

        // Set purchase cookie on order creation (like Stape)
        add_action('woocommerce_new_order', [$this, 'set_purchase_cookie']);
        add_action('woocommerce_thankyou', [$this, 'set_purchase_cookie']);

        // Add to cart data attributes (like Stape)
        add_filter('woocommerce_loop_add_to_cart_args', [$this, 'add_product_data_attributes'], 10, 2);
        add_filter('woocommerce_blocks_product_grid_item_html', [$this, 'add_blocks_product_data'], 10, 3);
        add_action('woocommerce_after_add_to_cart_button', [$this, 'inject_add_to_cart_data']);
        add_filter('woocommerce_cart_item_remove_link', [$this, 'add_remove_link_data'], 10, 2);

        // Related/Upsell tracking
        add_action('woocommerce_after_single_product_summary', [$this, 'related_products_tracking'], 25);
    }

    /**
     * Check if current user should be excluded from tracking
     */
    private function is_user_excluded()
    {
        if (!is_user_logged_in() || empty($this->excluded_roles)) {
            return false;
        }

        $user = wp_get_current_user();
        foreach ($user->roles as $role) {
            if (in_array($role, (array) $this->excluded_roles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if CartFlows plugin is active
     */
    private function is_cartflows_active()
    {
        return defined('CARTFLOWS_FILE') || class_exists('Cartflows_Loader');
    }

    /**
     * Set session cookie with value (like Stape's set_session)
     */
    public static function set_session($name, $value)
    {
        $domain = '.' . wp_parse_url(home_url(), PHP_URL_HOST);

        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie($name, $value, [
                'expires' => 0,
                'path' => '/',
                'domain' => $domain,
                'secure' => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($name, $value, 0, '/', $domain, is_ssl(), false);
        }

        $_COOKIE[$name] = $value;
    }

    /**
     * Get session cookie value (like Stape's get_session)
     */
    public static function get_session($name, $default = null)
    {
        return isset($_COOKIE[$name]) ? sanitize_text_field($_COOKIE[$name]) : $default;
    }

    /**
     * Delete cookie via JavaScript (like Stape's javascript_delete_cookie)
     */
    public static function javascript_delete_cookie($name)
    {
        $domain = '.' . wp_parse_url(home_url(), PHP_URL_HOST);
        ?>
        <script>
            document.cookie = '<?php echo esc_js($name); ?>=; max-age=-1; path=/; domain=<?php echo esc_js($domain); ?>;';
        </script>
        <?php
    }

    /**
     * Set purchase cookie on order creation (like Stape)
     */
    public function set_purchase_cookie($order_id)
    {
        // Instance flag prevents duplicate cookie sets in same request
        if ($this->is_order_created) {
            return;
        }
        $this->is_order_created = true;

        self::set_session(self::PURCHASE_COOKIE_KEY, $order_id);
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'pixelfly',
            PIXELFLY_PLUGIN_URL . 'assets/js/pixelfly.js',
            ['jquery'],
            PIXELFLY_VERSION,
            true
        );

        $user_data = [];
        if (function_exists('WC') && WC()->customer instanceof WC_Customer) {
            $user_data = PixelFly_User_Data::get_user_data();
        }

        wp_localize_script('pixelfly', 'pixelflyConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pixelfly_nonce'),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'debug' => (bool) get_option('pixelfly_debug_mode', false),
            'pageType' => $this->get_page_type(),
            'user_data' => $user_data,
        ]);
    }

    /**
     * Inject GTM head script
     */
    public function inject_gtm_head()
    {
        $use_standard_gtm = apply_filters('pixelfly_use_standard_gtm', true);
        if (!$use_standard_gtm) {
            return;
        }

        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id) || !preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
            return;
        }

        $gtm_domain = apply_filters('pixelfly_gtm_domain', 'www.googletagmanager.com');
        ?>
        <!-- Google Tag Manager (injected by PixelFly) -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://<?php echo esc_attr($gtm_domain); ?>/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php
    }

    /**
     * Inject GTM noscript in body
     */
    public function inject_gtm_body()
    {
        static $injected = false;
        if ($injected) {
            return;
        }

        $use_standard_gtm = apply_filters('pixelfly_use_standard_gtm', true);
        if (!$use_standard_gtm) {
            $custom_loader = class_exists('PixelFly_Custom_Loader') ? PixelFly_Custom_Loader::get_instance() : null;
            if ($custom_loader && $custom_loader->is_enabled()) {
                echo $custom_loader->get_noscript_iframe();
                $injected = true;
                return;
            }
        }

        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id) || !preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
            return;
        }

        $gtm_domain = apply_filters('pixelfly_gtm_domain', 'www.googletagmanager.com');
        ?>
        <!-- Google Tag Manager (noscript) - injected by PixelFly -->
        <noscript><iframe src="https://<?php echo esc_attr($gtm_domain); ?>/ns.html?id=<?php echo esc_attr($gtm_id); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php
        $injected = true;
    }

    /**
     * Initialize dataLayer and config
     */
    public function init_datalayer()
    {
        $debug = get_option('pixelfly_debug_mode', false);
        $page_info = PixelFly_Events::get_page_info();
        $page_type = $this->get_page_type();
        $customer_data = PixelFly_Events::build_customer_data();
        $cart_content = PixelFly_Events::build_cart_content();

        $initial_data = array_merge($page_info, $customer_data);
        $initial_data['cartContent'] = $cart_content;
        $initial_data['ecomm_pagetype'] = $page_type;

        // Add Facebook cookies if available
        $fbp = PixelFly_User_Data::get_fbp();
        $fbc = PixelFly_User_Data::get_fbc();
        if ($fbp) $initial_data['fbp'] = $fbp;
        if ($fbc) $initial_data['fbc'] = $fbc;
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.pixelflyConfig = {
                ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo wp_create_nonce('pixelfly_nonce'); ?>',
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                debug: <?php echo $debug ? 'true' : 'false'; ?>,
                pageType: '<?php echo esc_js($page_type); ?>'
            };

            // Initial dataLayer push
            dataLayer.push(<?php echo wp_json_encode($initial_data); ?>);

            // Page view event
            dataLayer.push({
                'event': 'page_view',
                'eventId': '<?php echo esc_js(PixelFly_Events::generate_event_id('pv')); ?>',
                'page_title': '<?php echo esc_js(wp_title('', false)); ?>',
                'page_location': '<?php echo esc_js(home_url(esc_url_raw($_SERVER['REQUEST_URI']))); ?>',
                'page_type': '<?php echo esc_js($page_type); ?>'
            });
        </script>
        <?php
    }

    /**
     * Get current page type
     */
    private function get_page_type()
    {
        if (is_front_page()) return 'home';
        if (function_exists('is_product') && is_product()) return 'product';
        if (function_exists('is_product_category') && is_product_category()) return 'category';
        if (function_exists('is_product_tag') && is_product_tag()) return 'tag';
        if (function_exists('is_shop') && is_shop()) return 'shop';
        if (function_exists('is_cart') && is_cart()) return 'cart';
        if (function_exists('is_checkout') && is_checkout()) {
            return function_exists('is_order_received_page') && is_order_received_page() ? 'purchase' : 'checkout';
        }
        if (function_exists('is_account_page') && is_account_page()) return 'account';
        if (is_search()) return 'search';
        return 'other';
    }

    /**
     * Add data attributes to add-to-cart buttons (like Stape)
     */
    public function add_product_data_attributes($args, $product)
    {
        if (!($product instanceof WC_Product) || $product->get_type() !== 'simple') {
            return $args;
        }

        $product_data = PixelFly_Events::build_product_data($product);

        if (!isset($args['attributes']) || !is_array($args['attributes'])) {
            $args['attributes'] = [];
        }

        // Add data attributes like Stape does
        foreach ($product_data as $key => $value) {
            $args['attributes']['data-pf_' . $key] = esc_attr($value);
        }

        $args['attributes']['data-pf_quantity'] = isset($args['quantity']) ? intval($args['quantity']) : 1;

        return $args;
    }

    /**
     * Add data attributes to WooCommerce Blocks products (like Stape)
     */
    public function add_blocks_product_data($html, $data, $product)
    {
        if (!($product instanceof WC_Product) || $product->get_type() !== 'simple') {
            return $html;
        }

        $product_data = PixelFly_Events::build_product_data($product);
        $attrs = [];

        foreach ($product_data as $key => $value) {
            $attrs[] = 'data-pf_' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        $html = str_replace('<li ', '<li ' . implode(' ', $attrs) . ' ', $html);

        return $html;
    }

    /**
     * Inject add to cart product data on single product page
     */
    public function inject_add_to_cart_data()
    {
        global $product;
        if (!$product || !($product instanceof WC_Product)) {
            return;
        }

        if ($product->get_type() === 'grouped') {
            return;
        }

        $product_data = PixelFly_Events::build_product_data($product);
        if ($product->is_type('variable')) {
            $product_data['item_group_id'] = (string) $product->get_id();
        }

        // Output as hidden inputs like Stape
        foreach ($product_data as $key => $value) {
            echo '<input type="hidden" name="pf_' . esc_attr($key) . '" value="' . esc_attr($value) . '">' . "\n";
        }
    }

    /**
     * Add data attributes to cart remove link (like Stape)
     */
    public function add_remove_link_data($link, $cart_item_key)
    {
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (empty($cart_item)) {
            return $link;
        }

        $product = $cart_item['data'];
        if (!($product instanceof WC_Product)) {
            return $link;
        }

        $product_data = PixelFly_Events::build_product_data($product);
        $product_data['quantity'] = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1;

        $attrs = [];
        foreach ($product_data as $key => $value) {
            $attrs[] = 'data-pf_' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        $link = str_replace('<a ', '<a ' . implode(' ', $attrs) . ' ', $link);

        return $link;
    }

    /**
     * View item event in wp_footer (like Stape)
     */
    public function view_item_event_footer()
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (isset(self::$fired_events['view_item'])) {
            return;
        }

        global $product;
        if (!($product instanceof WC_Product)) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('vi');
        $product_data = PixelFly_Events::build_product_data($product);
        $user_data = PixelFly_User_Data::get_user_data();
        $debug = get_option('pixelfly_debug_mode', false);

        if ($product->is_type('variable')) {
            $product_data['item_group_id'] = (string) $product->get_id();
        }

        $data_layer = [
            'event' => 'view_item',
            'eventId' => $event_id,
            'ecomm_pagetype' => 'product',
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'value' => (float) $product->get_price(),
                'items' => [$product_data],
            ],
        ];

        if (!empty($user_data)) {
            $data_layer['user_data'] = $user_data;
        }
        ?>
        <script type="text/javascript">
            dataLayer.push({ ecommerce: null });
            dataLayer.push(<?php echo wp_json_encode($data_layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>);
            <?php if ($debug): ?>
            console.log('[PixelFly] view_item event pushed', { event_id: '<?php echo esc_js($event_id); ?>' });
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_item'] = true;
    }

    /**
     * View item list event in wp_footer (like Stape)
     */
    public function view_item_list_event_footer()
    {
        if (!function_exists('is_shop')) {
            return;
        }

        // Only fire on shop, category, tag, or search pages
        if (!is_shop() && !is_product_category() && !is_product_tag() && !is_search()) {
            return;
        }

        if (isset(self::$fired_events['view_item_list'])) {
            return;
        }

        global $wp_query;
        if (!$wp_query->posts) {
            return;
        }

        $products = [];
        $index = 1;

        foreach ($wp_query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $product_data = PixelFly_Events::build_product_data($product);
                $product_data['index'] = $index++;
                $products[] = $product_data;
            }
        }

        if (empty($products)) {
            return;
        }

        // Determine list name and ID
        $list_name = 'Shop';
        $list_id = 'shop';
        $ecomm_pagetype = 'shop';

        if (is_product_category()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $list_name = $term->name;
                $list_id = 'category_' . $term->term_id;
                $ecomm_pagetype = 'category';
            }
        } elseif (is_product_tag()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $list_name = 'Tag: ' . $term->name;
                $list_id = 'tag_' . $term->term_id;
                $ecomm_pagetype = 'tag';
            }
        } elseif (is_search()) {
            $list_name = 'Search Results';
            $list_id = 'search';
            $ecomm_pagetype = 'search';
        }

        $event_id = PixelFly_Events::generate_event_id('vil');
        $debug = get_option('pixelfly_debug_mode', false);

        $data_layer = [
            'event' => 'view_item_list',
            'eventId' => $event_id,
            'ecomm_pagetype' => $ecomm_pagetype,
            'ecommerce' => [
                'currency' => get_woocommerce_currency(),
                'item_list_id' => $list_id,
                'item_list_name' => $list_name,
                'items' => $products,
            ],
        ];

        if (PixelFly_User_Data::is_user_data_enabled()) {
            $data_layer['user_data'] = PixelFly_User_Data::get_user_data();
        }
        ?>
        <script type="text/javascript">
            dataLayer.push({ ecommerce: null });
            dataLayer.push(<?php echo wp_json_encode($data_layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>);
            <?php if ($debug): ?>
            console.log('[PixelFly] view_item_list event pushed', { items: <?php echo count($products); ?>, list: '<?php echo esc_js($list_name); ?>' });
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_item_list'] = true;
    }

    /**
     * View cart event in wp_footer (like Stape)
     */
    public function view_cart_event_footer()
    {
        if (!function_exists('is_cart') || !is_cart()) {
            return;
        }

        if (isset(self::$fired_events['view_cart'])) {
            return;
        }

        $cart = WC()->cart->get_cart();
        if (empty($cart)) {
            return;
        }

        $cart_data = PixelFly_Events::build_cart_data();
        $event_id = PixelFly_Events::generate_event_id('vc');
        $debug = get_option('pixelfly_debug_mode', false);

        $data_layer = [
            'event' => 'view_cart',
            'eventId' => $event_id,
            'ecomm_pagetype' => 'basket',
            'cart_quantity' => count($cart),
            'cart_total' => (float) WC()->cart->get_cart_contents_total(),
            'ecommerce' => $cart_data,
        ];

        if (PixelFly_User_Data::is_user_data_enabled()) {
            $data_layer['user_data'] = PixelFly_User_Data::get_user_data();
        }
        ?>
        <script type="text/javascript">
            dataLayer.push({ ecommerce: null });
            dataLayer.push(<?php echo wp_json_encode($data_layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>);
            <?php if ($debug): ?>
            console.log('[PixelFly] view_cart event pushed');
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_cart'] = true;
    }

    /**
     * Begin checkout event in wp_footer (like Stape)
     */
    public function begin_checkout_event_footer()
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        if (isset(self::$fired_events['begin_checkout'])) {
            return;
        }

        // CartFlows compatibility
        if ($this->is_cartflows_active()) {
            if (function_exists('_is_wcf_checkout_type') && !_is_wcf_checkout_type()) {
                if (
                    (function_exists('_is_wcf_landing_type') && _is_wcf_landing_type()) ||
                    (function_exists('_is_wcf_optin_type') && _is_wcf_optin_type()) ||
                    (function_exists('_is_wcf_upsell_type') && _is_wcf_upsell_type()) ||
                    (function_exists('_is_wcf_downsell_type') && _is_wcf_downsell_type()) ||
                    (function_exists('_is_wcf_thankyou_type') && _is_wcf_thankyou_type())
                ) {
                    return;
                }
            }
        }

        $cart = WC()->cart->get_cart();
        if (empty($cart)) {
            return;
        }

        $cart_data = PixelFly_Events::build_cart_data();
        $event_id = PixelFly_Events::generate_event_id('bc');
        $user_data = PixelFly_User_Data::get_user_data();
        $debug = get_option('pixelfly_debug_mode', false);

        $data_layer = [
            'event' => 'begin_checkout',
            'eventId' => $event_id,
            'ecomm_pagetype' => 'basket',
            'ecommerce' => $cart_data,
        ];

        if (!empty($user_data)) {
            $data_layer['user_data'] = $user_data;
        }
        ?>
        <script type="text/javascript">
            (function() {
                if (window.pixelflyCheckoutFired) return;
                window.pixelflyCheckoutFired = true;

                dataLayer.push({ ecommerce: null });
                dataLayer.push(<?php echo wp_json_encode($data_layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>);
                <?php if ($debug): ?>
                console.log('[PixelFly] begin_checkout event pushed');
                <?php endif; ?>

                // Track shipping and payment info
                var shippingPushed = false;
                var paymentPushed = false;
                var cartItems = <?php echo wp_json_encode($cart_data['items']); ?>;
                var currency = '<?php echo esc_js(get_woocommerce_currency()); ?>';

                function checkAndPushShipping() {
                    if (shippingPushed) return;
                    var shippingEl = document.querySelector('input[name^="shipping_method"]:checked, .wc-block-components-shipping-rates-control input:checked');
                    if (shippingEl && shippingEl.value) {
                        dataLayer.push({ ecommerce: null });
                        dataLayer.push({
                            'event': 'add_shipping_info',
                            'eventId': 'asi_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                            'ecommerce': {
                                'currency': currency,
                                'shipping_tier': shippingEl.value,
                                'items': cartItems
                            }
                        });
                        shippingPushed = true;
                        <?php if ($debug): ?>console.log('[PixelFly] add_shipping_info event pushed');<?php endif; ?>
                    }
                }

                function checkAndPushPayment() {
                    if (paymentPushed) return;
                    var paymentEl = document.querySelector('input[name="payment_method"]:checked, .wc-block-components-radio-control input[name^="radio-control"]:checked');
                    if (paymentEl && paymentEl.value) {
                        dataLayer.push({ ecommerce: null });
                        dataLayer.push({
                            'event': 'add_payment_info',
                            'eventId': 'api_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                            'ecommerce': {
                                'currency': currency,
                                'payment_type': paymentEl.value,
                                'items': cartItems
                            }
                        });
                        paymentPushed = true;
                        <?php if ($debug): ?>console.log('[PixelFly] add_payment_info event pushed');<?php endif; ?>
                    }
                }

                setTimeout(function() {
                    checkAndPushShipping();
                    checkAndPushPayment();
                }, 1000);

                if (typeof jQuery !== 'undefined') {
                    jQuery(document.body).on('updated_checkout', function() {
                        checkAndPushShipping();
                        checkAndPushPayment();
                    });
                    jQuery(document).on('change', 'input[name^="shipping_method"], input[name="payment_method"]', function() {
                        checkAndPushShipping();
                        checkAndPushPayment();
                    });
                }

                var observer = new MutationObserver(function() {
                    checkAndPushShipping();
                    checkAndPushPayment();
                });
                var checkoutForm = document.querySelector('.woocommerce-checkout, .wp-block-woocommerce-checkout, .wc-block-checkout');
                if (checkoutForm) {
                    observer.observe(checkoutForm, { childList: true, subtree: true, attributes: true });
                }
            })();
        </script>
        <?php
        self::$fired_events['begin_checkout'] = true;
    }

    /**
     * Purchase event in wp_footer (like Stape's approach)
     * Uses cookie to detect order ID instead of relying on woocommerce_thankyou hook
     */
    public function purchase_event_footer()
    {
        // Check for purchase cookie set by woocommerce_new_order/woocommerce_thankyou
        $order_id = self::get_session(self::PURCHASE_COOKIE_KEY);

        // Also try to get from URL if on order-received page
        if (empty($order_id) && function_exists('is_order_received_page') && is_order_received_page()) {
            global $wp;
            if (isset($wp->query_vars['order-received'])) {
                $order_id = absint($wp->query_vars['order-received']);
            } elseif (isset($_GET['order-received'])) {
                $order_id = absint($_GET['order-received']);
            }
        }

        if (empty($order_id)) {
            return;
        }

        // Check if already fired in this request
        if (isset(self::$fired_events['purchase_' . $order_id])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return;
        }

        // Check if server-side already tracked this
        if ($order->get_meta('_pixelfly_server_tracked')) {
            $debug = get_option('pixelfly_debug_mode', false);
            if ($debug) {
                ?>
                <script>console.log('[PixelFly] Purchase already tracked (server-side), skipping order <?php echo esc_js($order_id); ?>');</script>
                <?php
            }
            // Delete the cookie
            self::javascript_delete_cookie(self::PURCHASE_COOKIE_KEY);
            return;
        }

        // Check delayed payment methods
        $delayed_enabled = get_option('pixelfly_delayed_enabled', true);
        $delayed_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        $payment_method = $order->get_payment_method();

        if ($delayed_enabled && in_array($payment_method, (array) $delayed_methods)) {
            $debug = get_option('pixelfly_debug_mode', false);
            if ($debug) {
                ?>
                <script>console.log('[PixelFly] Purchase event pending - delayed payment method (<?php echo esc_js($payment_method); ?>');</script>
                <?php
            }
            self::javascript_delete_cookie(self::PURCHASE_COOKIE_KEY);
            return;
        }

        // Build purchase data
        $purchase_data = PixelFly_Events::build_purchase_data($order);
        $debug = get_option('pixelfly_debug_mode', false);
        $is_new_customer = $this->is_new_customer($order);

        // Build comprehensive customer info
        $customer_info = $this->build_customer_info($order);

        // Build complete push data
        $data_layer = array_merge($customer_info, [
            'event' => 'purchase',
            'eventId' => $purchase_data['event_id'],
            'ecomm_pagetype' => 'purchase',
            'new_customer' => $is_new_customer ? 'true' : 'false',
            'orderData' => $purchase_data['orderData'],
            'ecommerce' => $purchase_data['ecommerce'],
        ]);

        // Add user_data
        $user_data = PixelFly_User_Data::get_user_data_from_order($order);
        if (!empty($user_data)) {
            $data_layer['user_data'] = $user_data;
            $data_layer['user_data']['new_customer'] = $is_new_customer ? 'true' : 'false';
        }

        // Mark order as tracked
        $order->update_meta_data('_pixelfly_tracked', true);
        $order->update_meta_data('_pixelfly_tracked_time', current_time('mysql'));
        $order->save();
        ?>
        <script type="text/javascript">
            (function() {
                window.dataLayer = window.dataLayer || [];

                var orderId = '<?php echo esc_js($order->get_id()); ?>';
                var purchaseKey = 'pixelfly_purchase_' + orderId;

                // Check if already tracked client-side
                var alreadyTracked = false;
                try {
                    if (localStorage.getItem(purchaseKey)) alreadyTracked = true;
                } catch (e) {}
                if (document.cookie.indexOf(purchaseKey + '=1') !== -1) alreadyTracked = true;

                if (alreadyTracked) {
                    <?php if ($debug): ?>console.log('[PixelFly] Purchase already tracked (client-side), skipping');<?php endif; ?>
                    return;
                }

                // Push purchase event
                dataLayer.push({ ecommerce: null });
                dataLayer.push(<?php echo wp_json_encode($data_layer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>);

                // Mark as tracked client-side
                try { localStorage.setItem(purchaseKey, '1'); } catch (e) {}
                var expires = new Date();
                expires.setTime(expires.getTime() + 365 * 24 * 60 * 60 * 1000);
                document.cookie = purchaseKey + '=1;expires=' + expires.toUTCString() + ';path=/';

                <?php if ($debug): ?>
                console.log('[PixelFly] purchase event pushed', {
                    order_id: '<?php echo esc_js($order_id); ?>',
                    event_id: '<?php echo esc_js($purchase_data['event_id']); ?>',
                    transaction_id: '<?php echo esc_js($order->get_order_number()); ?>',
                    value: <?php echo (float) $order->get_total(); ?>,
                    new_customer: <?php echo $is_new_customer ? 'true' : 'false'; ?>
                });
                <?php endif; ?>
            })();
        </script>
        <?php
        // Delete the purchase cookie via JavaScript (like Stape)
        self::javascript_delete_cookie(self::PURCHASE_COOKIE_KEY);

        self::$fired_events['purchase_' . $order_id] = true;
        self::$fired_events['purchase'] = true;
    }

    /**
     * Build customer info for purchase event
     */
    private function build_customer_info($order)
    {
        $customer_id = $order->get_customer_id();
        $billing_email = $order->get_billing_email();

        $info = [
            'customerTotalOrders' => 0,
            'customerTotalOrderValue' => 0,
            'customerFirstName' => $order->get_billing_first_name(),
            'customerLastName' => $order->get_billing_last_name(),
            'customerBillingFirstName' => $order->get_billing_first_name(),
            'customerBillingLastName' => $order->get_billing_last_name(),
            'customerBillingCompany' => $order->get_billing_company(),
            'customerBillingAddress1' => $order->get_billing_address_1(),
            'customerBillingAddress2' => $order->get_billing_address_2(),
            'customerBillingCity' => $order->get_billing_city(),
            'customerBillingState' => $order->get_billing_state(),
            'customerBillingPostcode' => $order->get_billing_postcode(),
            'customerBillingCountry' => $order->get_billing_country(),
            'customerBillingEmail' => $billing_email,
            'customerBillingEmailHash' => $billing_email ? hash('sha256', strtolower(trim($billing_email))) : '',
            'customerBillingPhone' => $order->get_billing_phone(),
            'customerShippingFirstName' => $order->get_shipping_first_name(),
            'customerShippingLastName' => $order->get_shipping_last_name(),
            'customerShippingCompany' => $order->get_shipping_company(),
            'customerShippingAddress1' => $order->get_shipping_address_1(),
            'customerShippingAddress2' => $order->get_shipping_address_2(),
            'customerShippingCity' => $order->get_shipping_city(),
            'customerShippingState' => $order->get_shipping_state(),
            'customerShippingPostcode' => $order->get_shipping_postcode(),
            'customerShippingCountry' => $order->get_shipping_country(),
        ];

        if ($customer_id) {
            $past_orders = wc_get_orders([
                'customer_id' => $customer_id,
                'status' => ['completed', 'processing'],
                'limit' => -1,
            ]);
            $info['customerTotalOrders'] = count($past_orders);
            $total_value = 0;
            foreach ($past_orders as $past_order) {
                $total_value += (float) $past_order->get_total();
            }
            $info['customerTotalOrderValue'] = round($total_value, 2);
        }

        return $info;
    }

    /**
     * Check if customer is new
     */
    private function is_new_customer($order)
    {
        $customer_id = $order->get_customer_id();

        if ($customer_id > 0) {
            $customer = new WC_Customer($customer_id);
            return $customer->get_order_count() === 1;
        }

        // Check by email for guest orders
        $email = $order->get_billing_email();
        if (empty($email)) {
            return true;
        }

        $orders = wc_get_orders([
            'billing_email' => $email,
            'status' => ['completed', 'processing'],
            'exclude' => [$order->get_id()],
            'limit' => 1,
        ]);

        return empty($orders);
    }

    /**
     * Related products tracking
     */
    public function related_products_tracking()
    {
        global $product;
        if (!$product) {
            return;
        }

        $debug = get_option('pixelfly_debug_mode', false);
        $tracked_lists = [];

        $related_ids = wc_get_related_products($product->get_id(), 12);
        if (!empty($related_ids)) {
            $related_products = [];
            $index = 0;
            foreach ($related_ids as $id) {
                $related_product = wc_get_product($id);
                if ($related_product) {
                    $product_data = PixelFly_Events::build_product_data($related_product);
                    $product_data['index'] = $index++;
                    $product_data['item_list_name'] = 'Related Products';
                    $product_data['item_list_id'] = 'related';
                    $related_products[] = $product_data;
                }
            }
            if (!empty($related_products)) {
                $tracked_lists['related'] = $related_products;
            }
        }

        $upsell_ids = $product->get_upsell_ids();
        if (!empty($upsell_ids)) {
            $upsell_products = [];
            $index = 0;
            foreach ($upsell_ids as $id) {
                $upsell_product = wc_get_product($id);
                if ($upsell_product) {
                    $product_data = PixelFly_Events::build_product_data($upsell_product);
                    $product_data['index'] = $index++;
                    $product_data['item_list_name'] = 'Upsell Products';
                    $product_data['item_list_id'] = 'upsells';
                    $upsell_products[] = $product_data;
                }
            }
            if (!empty($upsell_products)) {
                $tracked_lists['upsells'] = $upsell_products;
            }
        }

        if (empty($tracked_lists)) {
            return;
        }
        ?>
        <script>
            (function() {
                var tracked = {};
                window.pixelflyRelatedProducts = <?php echo wp_json_encode($tracked_lists); ?>;
                var currency = '<?php echo esc_js(get_woocommerce_currency()); ?>';

                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var listType = entry.target.getAttribute('data-pixelfly-list');
                            if (listType && !tracked[listType] && window.pixelflyRelatedProducts[listType]) {
                                tracked[listType] = true;
                                var products = window.pixelflyRelatedProducts[listType];
                                dataLayer.push({ ecommerce: null });
                                dataLayer.push({
                                    'event': 'view_item_list',
                                    'eventId': 'vil_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                                    'ecommerce': {
                                        'currency': currency,
                                        'item_list_id': listType,
                                        'item_list_name': products[0].item_list_name,
                                        'items': products
                                    }
                                });
                                <?php if ($debug): ?>console.log('[PixelFly] view_item_list (related) pushed', listType);<?php endif; ?>
                            }
                        }
                    });
                }, { threshold: 0.1 });

                setTimeout(function() {
                    document.querySelectorAll('.related.products, .upsells.products, .cross-sells').forEach(function(el) {
                        var listType = el.classList.contains('upsells') ? 'upsells' :
                                       el.classList.contains('cross-sells') ? 'crosssells' : 'related';
                        el.setAttribute('data-pixelfly-list', listType);
                        observer.observe(el);
                    });
                }, 500);
            })();
        </script>
        <?php
    }

    /**
     * WooCommerce Blocks compatibility script
     */
    public function blocks_compatibility_script()
    {
        if (!function_exists('is_cart')) {
            return;
        }

        if (!is_cart() && !is_checkout() && !is_shop() && !is_product_category()) {
            return;
        }
        ?>
        <script>
            (function() {
                function enhanceBlockProducts() {
                    document.querySelectorAll('.wc-block-grid__product, .wc-block-cart-items__row').forEach(function(el) {
                        if (el.getAttribute('data-pixelfly-enhanced')) return;
                        el.setAttribute('data-pixelfly-enhanced', '1');

                        var nameEl = el.querySelector('.wc-block-grid__product-title, .wc-block-cart-item__product-name');
                        var priceEl = el.querySelector('.wc-block-grid__product-price, .wc-block-cart-item__prices');
                        var linkEl = el.querySelector('a[href*="/product/"]');

                        if (nameEl && linkEl) {
                            var productData = {
                                item_name: nameEl.textContent.trim(),
                                item_id: linkEl.href.split('/').filter(Boolean).pop() || ''
                            };
                            if (priceEl) {
                                var priceText = priceEl.textContent.replace(/[^0-9.]/g, '');
                                productData.price = parseFloat(priceText) || 0;
                            }

                            // Add data attributes like Stape
                            for (var key in productData) {
                                el.setAttribute('data-pf_' + key, productData[key]);
                            }
                        }
                    });
                }

                enhanceBlockProducts();
                var observer = new MutationObserver(enhanceBlockProducts);
                observer.observe(document.body, { childList: true, subtree: true });
            })();
        </script>
        <?php
    }
}
