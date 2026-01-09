/**
 * PixelFly Frontend Tracker JavaScript
 * 
 * Handles AJAX add-to-cart, cart updates, and event tracking on the frontend
 */
(function($) {
    'use strict';

    // Ensure dataLayer exists
    window.dataLayer = window.dataLayer || [];

    /**
     * Generate unique event ID
     */
    function generateEventId(prefix) {
        prefix = prefix || 'event';
        return prefix + '_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Get Facebook IDs from cookies
     */
    function getFacebookIds() {
        var fbp = getCookie('_fbp') || '';
        var fbc = getCookie('_fbc') || '';
        var fbclid = new URLSearchParams(window.location.search).get('fbclid');

        // Generate _fbc from fbclid if not exists
        if (fbclid && !fbc) {
            fbc = 'fb.1.' + Date.now() + '.' + fbclid;
            document.cookie = '_fbc=' + fbc + '; path=/; max-age=7776000; samesite=lax';
        }

        return { fbp: fbp, fbc: fbc };
    }

    /**
     * Get cookie value by name
     */
    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return decodeURIComponent(parts.pop().split(';').shift());
        }
        return '';
    }

    /**
     * Get GA4 client ID
     */
    function getClientId() {
        var gaCookie = getCookie('_ga');
        if (gaCookie) {
            var parts = gaCookie.split('.');
            if (parts.length >= 4) {
                return parts[2] + '.' + parts[3];
            }
        }
        return '';
    }

    /**
     * Collect user data for events
     */
    function collectUserData() {
        var fbIds = getFacebookIds();
        return {
            fbp: fbIds.fbp,
            fbc: fbIds.fbc,
            client_id: getClientId()
        };
    }

    /**
     * Debug log
     */
    function debugLog(message, data) {
        if (window.pixelflyConfig && window.pixelflyConfig.debug) {
            console.log('[PixelFly] ' + message, data || '');
        }
    }

    // Document ready
    $(document).ready(function() {
        
        // Listen for WooCommerce AJAX add to cart
        $(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button) {
            var productId = $button.data('product_id');
            var quantity = parseInt($button.data('quantity')) || 1;

            // Get product data via AJAX
            $.post(pixelflyConfig.ajaxUrl, {
                action: 'pixelfly_get_product_data',
                product_id: productId,
                nonce: pixelflyConfig.nonce
            }, function(response) {
                if (response.success) {
                    var productData = response.data;
                    productData.quantity = quantity;

                    var eventData = {
                        'event': 'add_to_cart',
                        'eventId': generateEventId('atc'),
                        'ecommerce': {
                            'currency': pixelflyConfig.currency,
                            'value': productData.price * quantity,
                            'items': [productData]
                        },
                        'user_data': collectUserData()
                    };

                    dataLayer.push(eventData);
                    debugLog('add_to_cart event pushed', eventData);
                }
            });
        });

        // Handle quantity changes in cart (cart update)
        $(document.body).on('updated_cart_totals', function() {
            // Refresh cart view event
            $.post(pixelflyConfig.ajaxUrl, {
                action: 'pixelfly_get_cart_data',
                nonce: pixelflyConfig.nonce
            }, function(response) {
                if (response.success) {
                    var eventData = {
                        'event': 'view_cart',
                        'eventId': generateEventId('vc'),
                        'ecommerce': response.data
                    };

                    dataLayer.push(eventData);
                    debugLog('view_cart event pushed (cart updated)', eventData);
                }
            });
        });

        // Handle remove from cart
        $(document.body).on('removed_from_cart', function(event, fragments, cart_hash, $button) {
            var productId = $button.data('product_id');
            
            if (productId) {
                $.post(pixelflyConfig.ajaxUrl, {
                    action: 'pixelfly_get_product_data',
                    product_id: productId,
                    nonce: pixelflyConfig.nonce
                }, function(response) {
                    if (response.success) {
                        var productData = response.data;

                        var eventData = {
                            'event': 'remove_from_cart',
                            'eventId': generateEventId('rfc'),
                            'ecommerce': {
                                'currency': pixelflyConfig.currency,
                                'value': productData.price,
                                'items': [productData]
                            }
                        };

                        dataLayer.push(eventData);
                        debugLog('remove_from_cart event pushed', eventData);
                    }
                });
            }
        });

        // Select item on product list click
        $(document).on('click', '.products .product a.woocommerce-LoopProduct-link', function() {
            var $product = $(this).closest('.product');
            var productId = $product.find('.add_to_cart_button').data('product_id');
            
            if (productId) {
                $.post(pixelflyConfig.ajaxUrl, {
                    action: 'pixelfly_get_product_data',
                    product_id: productId,
                    nonce: pixelflyConfig.nonce
                }, function(response) {
                    if (response.success) {
                        var eventData = {
                            'event': 'select_item',
                            'eventId': generateEventId('si'),
                            'ecommerce': {
                                'items': [response.data]
                            }
                        };

                        dataLayer.push(eventData);
                        debugLog('select_item event pushed', eventData);
                    }
                });
            }
        });

        // Store UTM parameters in session storage on page load
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var utmFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid'];

            utmFields.forEach(function(field) {
                var value = urlParams.get(field);
                if (value) {
                    sessionStorage.setItem('pf_' + field, value);
                }
            });
        })();

        debugLog('PixelFly tracker initialized');
    });

})(jQuery);
