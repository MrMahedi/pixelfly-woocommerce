<?php

/**
 * PixelFly DataLayer Output
 *
 * Outputs dataLayer events for GTM integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_DataLayer
{

    /**
     * Constructor
     */
    public function __construct()
    {
        if (!get_option('pixelfly_datalayer_enabled', true)) {
            return;
        }

        // Inject GTM script in head (priority 1 - before other scripts)
        add_action('wp_head', [$this, 'inject_gtm_head'], 1);

        // Initialize dataLayer on every page
        add_action('wp_head', [$this, 'init_datalayer'], 2);

        // Inject GTM noscript in body
        add_action('wp_body_open', [$this, 'inject_gtm_body'], 1);

        // Page-specific events using WooCommerce hooks
        add_action('woocommerce_after_single_product', [$this, 'view_item_event']);
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_event']);
        add_action('woocommerce_after_cart', [$this, 'view_cart_event']);
        add_action('woocommerce_before_checkout_form', [$this, 'begin_checkout_event']);
        add_action('woocommerce_thankyou', [$this, 'purchase_event'], 10, 1);

        // Checkout step events
        add_action('woocommerce_checkout_after_customer_details', [$this, 'checkout_progress_script']);
    }

    /**
     * Inject GTM head script
     */
    public function inject_gtm_head()
    {
        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id)) {
            return;
        }

        // Validate GTM ID format (GTM-XXXXXX)
        if (!preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
            return;
        }
        ?>
        <!-- Google Tag Manager (injected by PixelFly) -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
        <!-- End Google Tag Manager -->
        <?php
    }

    /**
     * Inject GTM noscript in body
     */
    public function inject_gtm_body()
    {
        $gtm_id = get_option('pixelfly_gtm_container_id', '');
        if (empty($gtm_id)) {
            return;
        }

        // Validate GTM ID format (GTM-XXXXXX)
        if (!preg_match('/^GTM-[A-Z0-9]+$/i', $gtm_id)) {
            return;
        }
        ?>
        <!-- Google Tag Manager (noscript) - injected by PixelFly -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php
    }

    /**
     * Initialize dataLayer and config
     */
    public function init_datalayer()
    {
        $debug = get_option('pixelfly_debug_mode', false);
?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.pixelflyConfig = {
                ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo wp_create_nonce('pixelfly_nonce'); ?>',
                currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                debug: <?php echo $debug ? 'true' : 'false'; ?>
            };

            // Page view event
            dataLayer.push({
                'event': 'page_view',
                'eventId': '<?php echo esc_js(PixelFly_Events::generate_event_id('pv')); ?>',
                'page_title': '<?php echo esc_js(wp_title('', false)); ?>',
                'page_location': '<?php echo esc_js(home_url($_SERVER['REQUEST_URI'])); ?>'
            });
        </script>
    <?php
    }

    /**
     * View item event on product page
     */
    public function view_item_event()
    {
        global $product;
        if (!$product) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('vi');
        $product_data = PixelFly_Events::build_product_data($product);
        $user_data = PixelFly_User_Data::get_user_data();
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
            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                console.log('[PixelFly] view_item event pushed', {
                    event_id: '<?php echo esc_js($event_id); ?>'
                });
            <?php endif; ?>
        </script>
    <?php
    }

    /**
     * View item list event on shop/category pages
     */
    public function view_item_list_event()
    {
        global $wp_query;

        if (!$wp_query->posts) {
            return;
        }

        $products = [];
        foreach ($wp_query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $products[] = PixelFly_Events::build_product_data($product);
            }
        }

        if (empty($products)) {
            return;
        }

        // Get list name
        $list_name = 'Shop';
        if (is_product_category()) {
            $term = get_queried_object();
            $list_name = $term->name;
        } elseif (is_search()) {
            $list_name = 'Search Results';
        }

        $event_id = PixelFly_Events::generate_event_id('vil');
    ?>
        <script>
            dataLayer.push({
                'event': 'view_item_list',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': {
                    'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                    'item_list_name': '<?php echo esc_js($list_name); ?>',
                    'items': <?php echo wp_json_encode($products); ?>
                }
            });
            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                console.log('[PixelFly] view_item_list event pushed', {
                    items: <?php echo count($products); ?>
                });
            <?php endif; ?>
        </script>
    <?php
    }

    /**
     * View cart event
     */
    public function view_cart_event()
    {
        $cart_data = PixelFly_Events::build_cart_data();
        if (empty($cart_data['items'])) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('vc');
    ?>
        <script>
            dataLayer.push({
                'event': 'view_cart',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': <?php echo wp_json_encode($cart_data); ?>
            });
            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                console.log('[PixelFly] view_cart event pushed');
            <?php endif; ?>
        </script>
    <?php
    }

    /**
     * Begin checkout event
     */
    public function begin_checkout_event()
    {
        $cart_data = PixelFly_Events::build_cart_data();
        if (empty($cart_data['items'])) {
            return;
        }

        $event_id = PixelFly_Events::generate_event_id('bc');
        $user_data = PixelFly_User_Data::get_user_data();
    ?>
        <script>
            dataLayer.push({
                'event': 'begin_checkout',
                'eventId': '<?php echo esc_js($event_id); ?>',
                'ecommerce': <?php echo wp_json_encode($cart_data); ?>,
                'user_data': <?php echo wp_json_encode($user_data); ?>
            });
            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                console.log('[PixelFly] begin_checkout event pushed');
            <?php endif; ?>
        </script>
    <?php
    }

    /**
     * Checkout progress tracking script
     */
    public function checkout_progress_script()
    {
    ?>
        <script>
            (function() {
                var shippingPushed = false;
                var paymentPushed = false;

                // Watch for shipping method selection
                jQuery(document.body).on('updated_checkout', function() {
                    if (!shippingPushed) {
                        var shippingMethod = jQuery('input[name^="shipping_method"]:checked').val();
                        if (shippingMethod) {
                            dataLayer.push({
                                'event': 'add_shipping_info',
                                'eventId': 'asi_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                                'ecommerce': {
                                    'shipping_tier': shippingMethod
                                }
                            });
                            shippingPushed = true;
                            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                                console.log('[PixelFly] add_shipping_info event pushed');
                            <?php endif; ?>
                        }
                    }
                });

                // Watch for payment method selection
                jQuery(document.body).on('payment_method_selected', function() {
                    if (!paymentPushed) {
                        var paymentMethod = jQuery('input[name="payment_method"]:checked').val();
                        if (paymentMethod) {
                            dataLayer.push({
                                'event': 'add_payment_info',
                                'eventId': 'api_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
                                'ecommerce': {
                                    'payment_type': paymentMethod
                                }
                            });
                            paymentPushed = true;
                            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                                console.log('[PixelFly] add_payment_info event pushed');
                            <?php endif; ?>
                        }
                    }
                });

                // Also check on change
                jQuery(document).on('change', 'input[name="payment_method"]', function() {
                    jQuery(document.body).trigger('payment_method_selected');
                });
            })();
        </script>
        <?php
    }

    /**
     * Purchase event on thank you page
     */
    public function purchase_event($order_id)
    {
        // Check if already tracked
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if we've already tracked this order in this session
        if ($order->get_meta('_pixelfly_tracked')) {
            return;
        }

        // Check if delayed events are enabled for this payment method
        $delayed_enabled = get_option('pixelfly_delayed_enabled', true);
        $delayed_methods = get_option('pixelfly_delayed_payment_methods', ['cod']);
        $payment_method = $order->get_payment_method();

        if ($delayed_enabled && in_array($payment_method, $delayed_methods)) {
            // Don't fire - will be handled by delayed system
        ?>
            <script>
                console.log('[PixelFly] Purchase event pending - delayed payment method (<?php echo esc_js($payment_method); ?>)');
            </script>
        <?php
            return;
        }

        // Fire purchase event normally
        $purchase_data = PixelFly_Events::build_purchase_data($order);
        ?>
        <script>
            dataLayer.push({
                'event': 'purchase',
                'eventId': '<?php echo esc_js($purchase_data['event_id']); ?>',
                'ecommerce': <?php echo wp_json_encode($purchase_data['ecommerce']); ?>,
                'user_data': <?php echo wp_json_encode($purchase_data['user_data']); ?>
            });
            <?php if (get_option('pixelfly_debug_mode', false)): ?>
                console.log('[PixelFly] purchase event pushed', {
                    order_id: '<?php echo esc_js($order_id); ?>',
                    event_id: '<?php echo esc_js($purchase_data['event_id']); ?>'
                });
            <?php endif; ?>
        </script>
<?php

        // Mark as tracked
        $order->update_meta_data('_pixelfly_tracked', true);
        $order->save();
    }
}
