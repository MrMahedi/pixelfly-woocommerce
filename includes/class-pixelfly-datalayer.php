<?php

/**
 * PixelFly DataLayer Output
 *
 * Outputs dataLayer events for GTM integration
 * Compatible with all themes including Astra, Elementor, GeneratePress, OceanWP,
 * WooCommerce Blocks, and classic checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_DataLayer
{
    /**
     * Excluded user roles
     */
    private $excluded_roles = [];

    /**
     * Track if events have been fired to prevent duplicates
     */
    private static $fired_events = [];

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
        add_action('genesis_before', [$this, 'inject_gtm_body'], 1); // Genesis
        add_action('body_open', [$this, 'inject_gtm_body'], 1); // Some themes
        add_action('generate_before_header', [$this, 'inject_gtm_body'], 1); // GeneratePress
        add_action('astra_body_top', [$this, 'inject_gtm_body'], 1); // Astra

        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Page-specific events using multiple hooks for compatibility
        add_action('woocommerce_after_single_product', [$this, 'view_item_event']);
        add_action('woocommerce_after_single_product_summary', [$this, 'view_item_event'], 99);

        // Product list events
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_event']);
        add_action('woocommerce_after_shop_loop_item', [$this, 'inject_product_data']);

        // Related/Upsell/Cross-sell tracking
        add_action('woocommerce_after_single_product_summary', [$this, 'related_products_tracking'], 25);

        // Cart events
        add_action('woocommerce_after_cart', [$this, 'view_cart_event']);
        add_action('woocommerce_after_cart_table', [$this, 'view_cart_event']);
        add_action('woocommerce_cart_item_remove_link', [$this, 'inject_cart_item_data'], 10, 2);

        // Single product add to cart data
        add_action('woocommerce_after_add_to_cart_button', [$this, 'inject_add_to_cart_data']);

        // Thank you / Purchase - multiple hooks for compatibility
        add_action('woocommerce_thankyou', [$this, 'purchase_event'], 10, 1);
        add_action('woocommerce_before_thankyou', [$this, 'purchase_event'], 10, 1);

        // Fallback for block-based checkout - fire in footer if on order-received page
        add_action('wp_footer', [$this, 'purchase_event_footer_fallback']);

        // Checkout events - use wp_footer for better theme compatibility
        add_action('wp_footer', [$this, 'checkout_events_footer']);

        // WooCommerce Blocks compatibility
        add_action('wp_footer', [$this, 'blocks_compatibility_script']);
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
     * Enqueue frontend scripts
     */
    public function enqueue_scripts()
    {
        // Always load script on frontend for AJAX add to cart support
        // Products can appear anywhere (widgets, shortcodes, related products, etc.)
        wp_enqueue_script(
            'pixelfly-woocommerce',
            PIXELFLY_WC_PLUGIN_URL . 'assets/js/pixelfly-woocommerce.js',
            ['jquery'],
            PIXELFLY_WC_VERSION,
            true
        );

        // Localize script with config data (alternative to inline script)
        wp_localize_script('pixelfly-woocommerce', 'pixelflyConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pixelfly_nonce'),
            'currency' => get_woocommerce_currency(),
            'debug' => (bool) get_option('pixelfly_debug_mode', false),
            'pageType' => $this->get_page_type(),
        ]);
    }

    /**
     * Inject GTM head script
     */
    public function inject_gtm_head()
    {
        // Check if Custom Loader is handling GTM injection
        $use_standard_gtm = apply_filters('pixelfly_use_standard_gtm', true);
        if (!$use_standard_gtm) {
            // Custom Loader is handling GTM, skip standard injection
            return;
        }

        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id)) {
            return;
        }

        // Validate GTM ID format (GTM-XXXXXX)
        if (!preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
            return;
        }

        // Support for custom GTM domain (server-side GTM)
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

        // Check if Custom Loader is handling GTM injection
        $use_standard_gtm = apply_filters('pixelfly_use_standard_gtm', true);
        if (!$use_standard_gtm) {
            // Custom Loader handles its own noscript fallback
            $custom_loader = class_exists('PixelFly_Custom_Loader') ? PixelFly_Custom_Loader::get_instance() : null;
            if ($custom_loader && $custom_loader->is_enabled()) {
                echo $custom_loader->get_noscript_iframe();
                $injected = true;
                return;
            }
        }

        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id)) {
            return;
        }

        if (!preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
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

        // Get page info
        $page_info = PixelFly_Events::get_page_info();
        $page_type = $this->get_page_type();

        // Get customer data if logged in
        $customer_data = PixelFly_Events::build_customer_data();

        // Get cart content
        $cart_content = PixelFly_Events::build_cart_content();

        // Get Facebook cookies (fbp, fbc)
        $fbp = PixelFly_User_Data::get_fbp();
        $fbc = PixelFly_User_Data::get_fbc();

        // Build initial data object
        $initial_data = array_merge($page_info, $customer_data);
        $initial_data['cartContent'] = $cart_content;

        // Add Facebook cookies if available
        if ($fbp) {
            $initial_data['fbp'] = $fbp;
        }
        if ($fbc) {
            $initial_data['fbc'] = $fbc;
        }
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

            // Initial dataLayer push with comprehensive page, user, and cart info
            dataLayer.push(<?php echo wp_json_encode($initial_data); ?>);

            // Page view event
            dataLayer.push({
                'event': 'page_view',
                'eventId': '<?php echo esc_js(PixelFly_Events::generate_event_id('pv')); ?>',
                'page_title': '<?php echo esc_js(wp_title('', false)); ?>',
                'page_location': '<?php echo esc_js(home_url($_SERVER['REQUEST_URI'])); ?>',
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
        if (is_product()) return 'product';
        if (is_product_category()) return 'category';
        if (is_product_tag()) return 'tag';
        if (is_shop()) return 'shop';
        if (is_cart()) return 'cart';
        if (is_checkout()) return is_order_received_page() ? 'order_received' : 'checkout';
        if (is_account_page()) return 'account';
        if (is_search()) return 'search';
        return 'other';
    }

    /**
     * Inject product data as hidden element for JS tracking
     */
    public function inject_product_data()
    {
        global $product;
        if (!$product) {
            return;
        }

        $product_data = PixelFly_Events::build_product_data($product);
        // Add item_group_id for variable products
        if ($product->is_type('variation')) {
            $product_data['item_group_id'] = (string) $product->get_parent_id();
        }
        ?>
        <span class="pixelfly-product-data" style="display:none !important;"><?php echo wp_json_encode($product_data); ?></span>
        <?php
    }

    /**
     * Inject add to cart product data on single product page
     */
    public function inject_add_to_cart_data()
    {
        global $product;
        if (!$product) {
            return;
        }

        $product_data = PixelFly_Events::build_product_data($product);
        // Add item_group_id for variable products
        if ($product->is_type('variable')) {
            $product_data['item_group_id'] = (string) $product->get_id();
        }
        ?>
        <input type="hidden" class="pixelfly-product-data" value="<?php echo esc_attr(wp_json_encode($product_data)); ?>">
        <?php
    }

    /**
     * Inject cart item data for remove tracking
     */
    public function inject_cart_item_data($link, $cart_item_key)
    {
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (!$cart_item) {
            return $link;
        }

        $product = $cart_item['data'];
        if (!$product) {
            return $link;
        }

        $product_data = PixelFly_Events::build_product_data($product, $cart_item['quantity']);
        echo '<span class="pixelfly-product-data" style="display:none !important;">' . wp_json_encode($product_data) . '</span>';

        return $link;
    }

    /**
     * View item event on product page
     */
    public function view_item_event()
    {
        if (isset(self::$fired_events['view_item'])) {
            return;
        }

        global $product;
        if (!$product) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('vi');
        $product_data = PixelFly_Events::build_product_data($product);
        $user_data = PixelFly_User_Data::get_user_data();
        $debug = get_option('pixelfly_debug_mode', false);

        // Add item_group_id for variable products
        if ($product->is_type('variable')) {
            $product_data['item_group_id'] = (string) $product->get_id();
        }
        ?>
        <script>
            dataLayer.push({
                'event': 'view_item',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'value': <?php echo (float) $product->get_price(); ?>,
                    'items': [<?php echo wp_json_encode($product_data); ?>]
                },
                'user_data': <?php echo wp_json_encode($user_data); ?>
            });
            <?php if ($debug): ?>
                console.log('[PixelFly] view_item event pushed', { event_id: '<?php echo esc_js($event_id); ?>' });
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_item'] = true;
    }

    /**
     * View item list event on shop/category pages
     */
    public function view_item_list_event()
    {
        if (isset(self::$fired_events['view_item_list'])) {
            return;
        }

        global $wp_query;

        if (!$wp_query->posts) {
            return;
        }

        $products = [];
        $index = 0;
        $posts_per_page = get_option('posts_per_page', 10);
        $paged = max(1, get_query_var('paged', 1));

        foreach ($wp_query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $product_data = PixelFly_Events::build_product_data($product);
                // Add index with pagination support
                $product_data['index'] = $index + (($paged - 1) * $posts_per_page);
                $products[] = $product_data;
                $index++;
            }
        }

        if (empty($products)) {
            return;
        }

        // Get list name and ID
        $list_name = 'Shop';
        $list_id = 'shop';
        if (is_product_category()) {
            $term = get_queried_object();
            $list_name = $term->name;
            $list_id = 'category_' . $term->term_id;
        } elseif (is_product_tag()) {
            $term = get_queried_object();
            $list_name = 'Tag: ' . $term->name;
            $list_id = 'tag_' . $term->term_id;
        } elseif (is_search()) {
            $list_name = 'Search Results';
            $list_id = 'search';
        }

        $event_id = PixelFly_Events::generate_event_id('vil');
        $debug = get_option('pixelfly_debug_mode', false);
        ?>
        <script>
            dataLayer.push({
                'event': 'view_item_list',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'item_list_id': '<?php echo esc_js($list_id); ?>',
                    'item_list_name': '<?php echo esc_js($list_name); ?>',
                    'items': <?php echo wp_json_encode($products); ?>
                }
            });
            <?php if ($debug): ?>
                console.log('[PixelFly] view_item_list event pushed', { items: <?php echo count($products); ?>, list: '<?php echo esc_js($list_name); ?>' });
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_item_list'] = true;
    }

    /**
     * Track related, upsell, and cross-sell products (lazy loaded on scroll)
     */
    public function related_products_tracking()
    {
        global $product;
        if (!$product) {
            return;
        }

        $debug = get_option('pixelfly_debug_mode', false);
        $tracked_lists = [];

        // Related products
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

        // Upsells
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

                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var listType = entry.target.getAttribute('data-pixelfly-list');
                            if (listType && !tracked[listType] && window.pixelflyRelatedProducts[listType]) {
                                tracked[listType] = true;
                                var products = window.pixelflyRelatedProducts[listType];
                                dataLayer.push({
                                    'event': 'view_item_list',
                                    'eventId': 'vil_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                                    'ecommerce': {
                                        'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                                        'item_list_id': listType,
                                        'item_list_name': products[0].item_list_name,
                                        'items': products
                                    }
                                });
                                <?php if ($debug): ?>
                                    console.log('[PixelFly] view_item_list (related) pushed', listType);
                                <?php endif; ?>
                            }
                        }
                    });
                }, { threshold: 0.1 });

                // Find and observe related product sections
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
     * View cart event
     */
    public function view_cart_event()
    {
        if (isset(self::$fired_events['view_cart'])) {
            return;
        }

        $cart_data = PixelFly_Events::build_cart_data();
        if (empty($cart_data['items'])) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('vc');
        $debug = get_option('pixelfly_debug_mode', false);
        ?>
        <script>
            dataLayer.push({
                'event': 'view_cart',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': <?php echo wp_json_encode($cart_data); ?>
            });
            <?php if ($debug): ?>
                console.log('[PixelFly] view_cart event pushed');
            <?php endif; ?>
        </script>
        <?php
        self::$fired_events['view_cart'] = true;
    }

    /**
     * Checkout events fired in footer for theme compatibility
     */
    public function checkout_events_footer()
    {
        if (!is_checkout() || is_order_received_page()) {
            return;
        }

        $cart_data = PixelFly_Events::build_cart_data();
        if (empty($cart_data['items'])) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('bc');
        $user_data = PixelFly_User_Data::get_user_data();
        $debug = get_option('pixelfly_debug_mode', false);
        ?>
        <script>
            (function() {
                if (window.pixelflyCheckoutFired) return;
                window.pixelflyCheckoutFired = true;

                dataLayer.push({
                    'event': 'begin_checkout',
                    'eventId': '<?php echo esc_js($event_id); ?>',
                    'ecommerce': <?php echo wp_json_encode($cart_data); ?>,
                    'user_data': <?php echo wp_json_encode($user_data); ?>
                });
                <?php if ($debug): ?>
                    console.log('[PixelFly] begin_checkout event pushed');
                <?php endif; ?>

                // Track shipping and payment info
                var shippingPushed = false;
                var paymentPushed = false;
                var cartItems = <?php echo wp_json_encode($cart_data['items']); ?>;

                function checkAndPushShipping() {
                    if (shippingPushed) return;
                    var shippingEl = document.querySelector('input[name^="shipping_method"]:checked, .wc-block-components-shipping-rates-control input:checked');
                    if (shippingEl && shippingEl.value) {
                        dataLayer.push({
                            'event': 'add_shipping_info',
                            'eventId': 'asi_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                            'ecommerce': {
                                'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                                'shipping_tier': shippingEl.value,
                                'items': cartItems
                            }
                        });
                        shippingPushed = true;
                        <?php if ($debug): ?>
                            console.log('[PixelFly] add_shipping_info event pushed');
                        <?php endif; ?>
                    }
                }

                function checkAndPushPayment() {
                    if (paymentPushed) return;
                    var paymentEl = document.querySelector('input[name="payment_method"]:checked, .wc-block-components-radio-control input[name^="radio-control"]:checked');
                    if (paymentEl && paymentEl.value) {
                        dataLayer.push({
                            'event': 'add_payment_info',
                            'eventId': 'api_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                            'ecommerce': {
                                'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                                'payment_type': paymentEl.value,
                                'items': cartItems
                            }
                        });
                        paymentPushed = true;
                        <?php if ($debug): ?>
                            console.log('[PixelFly] add_payment_info event pushed');
                        <?php endif; ?>
                    }
                }

                // Initial check after small delay
                setTimeout(function() {
                    checkAndPushShipping();
                    checkAndPushPayment();
                }, 1000);

                // Watch for changes - Classic checkout
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

                // Watch for changes - Block checkout using MutationObserver
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
    }

    /**
     * WooCommerce Blocks compatibility script
     */
    public function blocks_compatibility_script()
    {
        if (!is_cart() && !is_checkout() && !is_shop() && !is_product_category()) {
            return;
        }
        ?>
        <script>
            (function() {
                // Add data attributes to block product elements for tracking
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
                            var dataEl = document.createElement('span');
                            dataEl.className = 'pixelfly-product-data';
                            dataEl.style.display = 'none';
                            dataEl.textContent = JSON.stringify(productData);
                            el.appendChild(dataEl);
                        }
                    });
                }

                // Run on load and observe for dynamic changes
                enhanceBlockProducts();
                var observer = new MutationObserver(enhanceBlockProducts);
                observer.observe(document.body, { childList: true, subtree: true });
            })();
        </script>
        <?php
    }

    /**
     * Purchase event on thank you page
     */
    public function purchase_event($order_id)
    {
        // Ensure order_id is valid
        if (!$order_id) {
            return;
        }

        // Check if already fired in this request
        if (isset(self::$fired_events['purchase_' . $order_id])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if we've already tracked this order (server-side check)
        if ($order->get_meta('_pixelfly_tracked')) {
            $debug = get_option('pixelfly_debug_mode', false);
            if ($debug) {
                ?>
                <script>console.log('[PixelFly] Purchase already tracked (server-side), skipping order <?php echo esc_js($order_id); ?>');</script>
                <?php
            }
            return;
        }

        // Check if delayed events are enabled for this payment method
        $delayed_enabled = get_option('pixelfly_delayed_enabled', true);
        $delayed_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        $payment_method = $order->get_payment_method();

        if ($delayed_enabled && in_array($payment_method, (array) $delayed_methods)) {
            $debug = get_option('pixelfly_debug_mode', false);
            if ($debug) {
                ?>
                <script>
                    console.log('[PixelFly] Purchase event pending - delayed payment method (<?php echo esc_js($payment_method); ?>)');
                </script>
                <?php
            }
            return;
        }

        // Build purchase data
        $purchase_data = PixelFly_Events::build_purchase_data($order);
        $debug = get_option('pixelfly_debug_mode', false);

        // Determine if new or returning customer
        $is_new_customer = $this->is_new_customer($order->get_billing_email(), $order->get_id());

        // Get customer data for the order (similar to GTM4WP structure)
        $customer_id = $order->get_customer_id();
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();

        $customer_info = [
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
            'customerBillingPhone' => $billing_phone,
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

        // Get customer order history if they have an account
        if ($customer_id) {
            $past_orders = wc_get_orders([
                'customer_id' => $customer_id,
                'status' => ['completed', 'processing'],
                'limit' => -1,
            ]);
            $customer_info['customerTotalOrders'] = count($past_orders);
            $total_value = 0;
            foreach ($past_orders as $past_order) {
                $total_value += (float) $past_order->get_total();
            }
            $customer_info['customerTotalOrderValue'] = round($total_value, 2);
        }

        // Build complete purchase push data
        $push_data = array_merge($customer_info, [
            'cartContent' => [
                'totals' => [
                    'applied_coupons' => $order->get_coupon_codes(),
                    'discount_total' => (float) $order->get_discount_total(),
                    'subtotal' => (float) $order->get_subtotal(),
                    'total' => (float) $order->get_total(),
                ],
                'items' => $purchase_data['ecommerce']['items'],
            ],
            'orderData' => $purchase_data['orderData'],
            'new_customer' => $is_new_customer,
            'event' => 'purchase',
            'eventId' => $purchase_data['event_id'],
            'ecommerce' => $purchase_data['ecommerce'],
        ]);

        // Mark as tracked in order meta FIRST to prevent race conditions
        $order->update_meta_data('_pixelfly_tracked', true);
        $order->update_meta_data('_pixelfly_tracked_time', current_time('mysql'));
        $order->save();
        ?>
        <script>
            (function() {
                // Ensure dataLayer exists
                window.dataLayer = window.dataLayer || [];

                var orderId = '<?php echo esc_js($order->get_id()); ?>';
                var purchaseKey = 'pixelfly_purchase_' + orderId;

                // Check if already tracked in client (localStorage + cookie)
                var alreadyTracked = false;
                try {
                    if (localStorage.getItem(purchaseKey)) {
                        alreadyTracked = true;
                    }
                } catch (e) {}
                if (document.cookie.indexOf(purchaseKey + '=1') !== -1) {
                    alreadyTracked = true;
                }

                if (alreadyTracked) {
                    <?php if ($debug): ?>
                        console.log('[PixelFly] Purchase already tracked (client-side), skipping');
                    <?php endif; ?>
                    return;
                }

                // Push purchase event
                var purchaseData = <?php echo wp_json_encode($push_data); ?>;
                window.dataLayer.push(purchaseData);

                // Mark as tracked in client
                try {
                    localStorage.setItem(purchaseKey, '1');
                } catch (e) {}
                var expires = new Date();
                expires.setTime(expires.getTime() + 365 * 24 * 60 * 60 * 1000);
                document.cookie = purchaseKey + '=1;expires=' + expires.toUTCString() + ';path=/';

                <?php if ($debug): ?>
                    console.log('[PixelFly] purchase event pushed', {
                        order_id: '<?php echo esc_js($order_id); ?>',
                        event_id: '<?php echo esc_js($purchase_data['event_id']); ?>',
                        transaction_id: purchaseData.ecommerce.transaction_id,
                        value: purchaseData.ecommerce.value,
                        new_customer: <?php echo $is_new_customer ? 'true' : 'false'; ?>
                    });
                <?php endif; ?>
            })();
        </script>
        <?php

        // Mark as fired in this request
        self::$fired_events['purchase_' . $order_id] = true;
        self::$fired_events['purchase'] = true;
    }

    /**
     * Fallback purchase event for block-based checkout
     * Fires in wp_footer if on order-received page
     */
    public function purchase_event_footer_fallback()
    {
        // Only run on order-received page
        if (!is_order_received_page()) {
            return;
        }

        // Check if purchase event already fired
        if (isset(self::$fired_events['purchase'])) {
            return;
        }

        // Get order ID from URL
        global $wp;
        $order_id = 0;

        // Try different methods to get order ID
        if (isset($wp->query_vars['order-received'])) {
            $order_id = absint($wp->query_vars['order-received']);
        } elseif (isset($_GET['order-received'])) {
            $order_id = absint($_GET['order-received']);
        } elseif (isset($_GET['order_id'])) {
            $order_id = absint($_GET['order_id']);
        }

        // For block checkout, try to get from URL path
        if (!$order_id) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            if (preg_match('/order-received\/(\d+)/', $path, $matches)) {
                $order_id = absint($matches[1]);
            }
        }

        if ($order_id) {
            $debug = get_option('pixelfly_debug_mode', false);
            if ($debug) {
                ?>
                <script>console.log('[PixelFly] Footer fallback: firing purchase event for order <?php echo esc_js($order_id); ?>');</script>
                <?php
            }
            $this->purchase_event($order_id);
            self::$fired_events['purchase'] = true;
        }
    }

    /**
     * Check if customer is new (first order)
     */
    private function is_new_customer($email, $current_order_id)
    {
        if (empty($email)) {
            return true;
        }

        $orders = wc_get_orders([
            'billing_email' => $email,
            'status' => ['completed', 'processing'],
            'exclude' => [$current_order_id],
            'limit' => 1,
        ]);

        return empty($orders);
    }
}
