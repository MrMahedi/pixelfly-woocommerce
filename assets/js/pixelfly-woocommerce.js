/**
 * PixelFly WooCommerce DataLayer
 *
 * Handles client-side e-commerce event tracking for GTM
 * Compatible with classic WooCommerce, WooCommerce Blocks, and all themes
 */
(function() {
    'use strict';

    // Config will be set by wp_localize_script or inline script
    // Use defaults if not available yet
    var config = window.pixelflyConfig || {
        currency: 'USD',
        debug: false
    };
    var debug = config.debug || false;

    // Utility functions
    function log() {
        if (debug) {
            console.log.apply(console, ['[PixelFly]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    function generateEventId(prefix) {
        return (prefix || 'event') + '_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    function pushDataLayer(data) {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(data);
        log('Event pushed:', data.event, data);
    }

    // =====================================================
    // FACEBOOK COOKIE HANDLING (_fbp and _fbc)
    // =====================================================

    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return decodeURIComponent(parts.pop().split(';').shift());
        }
        return '';
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; samesite=lax';
    }

    /**
     * Initialize Facebook cookies (_fbc from fbclid)
     * This runs on every page load to ensure _fbc is set when user comes from Facebook ad
     */
    function initFacebookCookies() {
        var urlParams = new URLSearchParams(window.location.search);
        var fbclid = urlParams.get('fbclid');

        // If fbclid is in URL and _fbc doesn't exist, create it
        if (fbclid && !getCookie('_fbc')) {
            // _fbc format: fb.{subdomain_index}.{creation_time}.{fbclid}
            // subdomain_index is 1 for first-party cookies
            var fbc = 'fb.1.' + Date.now() + '.' + fbclid;
            setCookie('_fbc', fbc, 90); // 90 days expiry (same as Meta Pixel)
            log('Created _fbc cookie from fbclid:', fbc);

            // Also push to dataLayer so GTM can read it immediately
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                'fbc': fbc
            });
        }

        // Log current Facebook cookies for debugging
        var currentFbc = getCookie('_fbc');
        var currentFbp = getCookie('_fbp');
        if (currentFbc || currentFbp) {
            log('Facebook cookies:', { fbp: currentFbp, fbc: currentFbc });
        }
    }

    // Get Facebook IDs for events
    function getFacebookIds() {
        return {
            fbp: getCookie('_fbp') || '',
            fbc: getCookie('_fbc') || ''
        };
    }

    // =====================================================
    // PRODUCT DATA EXTRACTION
    // =====================================================

    // Get product data from DOM element
    function getProductDataFromElement(element) {
        var dataEl = element.querySelector('.pixelfly-product-data');
        if (!dataEl) {
            // Try parent elements
            var parent = element.closest('.product, .wc-block-grid__product');
            if (parent) {
                dataEl = parent.querySelector('.pixelfly-product-data');
            }
        }
        if (dataEl) {
            try {
                return JSON.parse(dataEl.textContent || dataEl.innerText);
            } catch (e) {
                log('Failed to parse product data', e);
            }
        }
        return null;
    }

    // Get product data from form (single product page)
    function getProductDataFromForm(form) {
        // Try to find data element inside form first
        var dataEl = form ? form.querySelector('.pixelfly-product-data, input.pixelfly-product-data') : null;

        // If not found in form, search the product container or entire page
        if (!dataEl) {
            var productContainer = document.querySelector('.product, .single-product');
            if (productContainer) {
                dataEl = productContainer.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
            }
        }

        // Last resort - search entire page
        if (!dataEl) {
            dataEl = document.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
        }

        if (dataEl) {
            try {
                var jsonData = dataEl.value || dataEl.textContent || dataEl.innerText;
                log('Found product data element', jsonData);
                return JSON.parse(jsonData);
            } catch (e) {
                log('Failed to parse form product data', e);
            }
        }

        // Fallback: try to extract from page if no data element found
        log('No pixelfly-product-data element found, trying fallback extraction');
        return getProductDataFromPage();
    }

    // Fallback: extract product data from page elements
    function getProductDataFromPage() {
        var productData = null;

        // Try to get product ID from form
        var form = document.querySelector('form.cart');
        var addToCartInput = form ? form.querySelector('button[name="add-to-cart"], input[name="add-to-cart"]') : null;
        var productId = addToCartInput ? (addToCartInput.value || addToCartInput.getAttribute('value')) : null;

        // Also check for product_id in form action or hidden inputs
        if (!productId && form) {
            var hiddenInput = form.querySelector('input[name="product_id"], input[name="add-to-cart"]');
            if (hiddenInput) {
                productId = hiddenInput.value;
            }
        }

        // Try body class for product ID
        if (!productId) {
            var bodyClasses = document.body.className.split(' ');
            for (var i = 0; i < bodyClasses.length; i++) {
                var match = bodyClasses[i].match(/postid-(\d+)/);
                if (match) {
                    productId = match[1];
                    break;
                }
            }
        }

        // Get product name from page
        var productName = '';
        var nameEl = document.querySelector('.product_title, .entry-title, h1.product-title');
        if (nameEl) {
            productName = nameEl.textContent.trim();
        }

        // Get price
        var price = 0;
        var priceEl = document.querySelector('.product .price ins .woocommerce-Price-amount, .product .price > .woocommerce-Price-amount, .product p.price .woocommerce-Price-amount');
        if (priceEl) {
            price = parseFloat(priceEl.textContent.replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
        }

        if (productId && productName) {
            productData = {
                item_id: productId.toString(),
                item_name: productName,
                price: price,
                quantity: 1
            };
            log('Extracted product data from page', productData);
        }

        return productData;
    }

    // =====================================================
    // ADD TO CART TRACKING
    // =====================================================

    // Track add to cart from product lists (AJAX)
    function initAddToCartListTracking() {
        // Capture product data on click (before AJAX completes)
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.add_to_cart_button, .ajax_add_to_cart, [data-product_id]');
            if (!button) return;

            // Skip single product add to cart buttons - handled by initAddToCartFormTracking
            if (button.classList.contains('single_add_to_cart_button')) {
                return;
            }

            // Skip variable/grouped products (they go to product page)
            if (button.classList.contains('product_type_variable') ||
                button.classList.contains('product_type_grouped')) {
                return;
            }

            var productData = getProductDataFromElement(button);
            if (!productData) {
                // Try to get from data attributes (WooCommerce stores these on buttons)
                var productId = button.getAttribute('data-product_id');
                var productSku = button.getAttribute('data-product_sku') || '';
                var quantity = parseInt(button.getAttribute('data-quantity')) || 1;

                // Try to find product info from the product container
                var productContainer = button.closest('.product, .wc-block-grid__product, li.product');
                var productName = 'Product';
                var price = 0;

                if (productContainer) {
                    var titleEl = productContainer.querySelector('.woocommerce-loop-product__title, .wc-block-grid__product-title, h2, h3');
                    if (titleEl) {
                        productName = titleEl.textContent.trim();
                    }
                    var priceEl = productContainer.querySelector('.price ins .amount, .price > .amount, .wc-block-grid__product-price');
                    if (priceEl) {
                        price = parseFloat(priceEl.textContent.replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                    }
                }

                productData = {
                    item_id: productId,
                    item_name: productName,
                    sku: productSku || productId,
                    price: price,
                    quantity: quantity
                };
            }

            if (productData && productData.item_id) {
                // Store for AJAX completion - mark as list add to cart
                window.pixelflyPendingAddToCart = productData;
                window.pixelflyPendingButton = button;
                window.pixelflyListProductPending = true;
                window.pixelflySingleProductPending = false;
                log('Add to cart click captured (list)', productData);
            }
        }, true); // Use capture phase to get it before WooCommerce

        // Listen for WooCommerce AJAX add to cart success
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                log('added_to_cart event triggered (list handler)');

                // Skip if this is a single product page event - handled by initAddToCartFormTracking
                if (window.pixelflySingleProductPending) {
                    log('Skipping list handler - single product pending');
                    return;
                }

                // Skip if already handled
                if (!window.pixelflyListProductPending && !window.pixelflyPendingAddToCart) {
                    log('Skipping list handler - no pending data');
                    return;
                }

                var productData = window.pixelflyPendingAddToCart;

                // If no pending data, try to get from button
                if (!productData && button && button.length) {
                    var btn = button[0] || button;
                    // Skip if this is a single product button
                    if (btn.classList && btn.classList.contains('single_add_to_cart_button')) {
                        return;
                    }
                    productData = getProductDataFromElement(btn);
                    if (!productData) {
                        productData = {
                            item_id: btn.getAttribute('data-product_id'),
                            item_name: 'Product',
                            price: 0,
                            quantity: parseInt(btn.getAttribute('data-quantity')) || 1
                        };
                    }
                }

                if (productData && productData.item_id) {
                    pushDataLayer({
                        'event': 'add_to_cart',
                        'eventId': generateEventId('atc'),
                        'ecommerce': {
                            'currency': config.currency || 'USD',
                            'value': (productData.price || 0) * (productData.quantity || 1),
                            'items': [productData]
                        }
                    });
                } else {
                    log('No product data available for add_to_cart event');
                }

                window.pixelflyPendingAddToCart = null;
                window.pixelflyPendingButton = null;
                window.pixelflyListProductPending = false;
            });

            // Also listen for WooCommerce AJAX complete as backup
            jQuery(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('wc-ajax=add_to_cart') > -1) {
                    // Give a small delay for the added_to_cart event to fire first
                    setTimeout(function() {
                        // Only fire if we have pending list data (not single product)
                        if (window.pixelflyPendingAddToCart && window.pixelflyListProductPending && !window.pixelflySingleProductPending) {
                            log('AJAX complete fallback - firing add_to_cart (list)');
                            pushDataLayer({
                                'event': 'add_to_cart',
                                'eventId': generateEventId('atc'),
                                'ecommerce': {
                                    'currency': config.currency || 'USD',
                                    'value': (window.pixelflyPendingAddToCart.price || 0) * (window.pixelflyPendingAddToCart.quantity || 1),
                                    'items': [window.pixelflyPendingAddToCart]
                                }
                            });
                            window.pixelflyPendingAddToCart = null;
                            window.pixelflyListProductPending = false;
                        }
                    }, 100);
                }
            });
        }
    }

    // Track add to cart from single product page
    function initAddToCartFormTracking() {
        // Flag to track if we're on a single product page
        var isSingleProductPage = document.body.classList.contains('single-product') ||
                                  document.querySelector('form.cart .single_add_to_cart_button');

        // Capture click on single product add to cart button
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.single_add_to_cart_button, form.cart button[type="submit"], .ast-sticky-add-to-cart .single_add_to_cart_button');
            if (!button) return;

            var form = button.closest('form.cart');
            // For sticky add to cart buttons, find the main form
            if (!form && document.querySelector('form.cart')) {
                form = document.querySelector('form.cart');
            }
            if (!form) return;

            var productData = getProductDataFromForm(form);
            var quantityInput = form.querySelector('input[name="quantity"]');
            var quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;

            // Handle variable products
            var variationIdInput = form.querySelector('input[name="variation_id"]');
            if (variationIdInput && variationIdInput.value && window.pixelflySelectedVariation) {
                productData = Object.assign({}, window.pixelflySelectedVariation);
            }

            if (productData) {
                productData.quantity = quantity;
                // Store for potential AJAX completion
                window.pixelflyPendingSingleProductCart = productData;
                // Also set as pending add to cart for the added_to_cart event
                window.pixelflyPendingAddToCart = productData;
                window.pixelflySingleProductPending = true;
                log('Single product add to cart click captured', productData);
            }
        }, true);

        // Listen for form submit (for non-AJAX themes)
        document.addEventListener('submit', function(e) {
            var form = e.target.closest('form.cart');
            if (!form) return;

            var productData = window.pixelflyPendingSingleProductCart;
            if (!productData) {
                productData = getProductDataFromForm(form);
                var quantityInput = form.querySelector('input[name="quantity"]');
                var quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;

                // Handle variable products
                var variationIdInput = form.querySelector('input[name="variation_id"]');
                if (variationIdInput && variationIdInput.value && window.pixelflySelectedVariation) {
                    productData = Object.assign({}, window.pixelflySelectedVariation);
                }

                if (productData) {
                    productData.quantity = quantity;
                }
            }

            // Only fire for non-AJAX submits (check if button has ajax class)
            var submitBtn = form.querySelector('.single_add_to_cart_button');
            var isAjax = submitBtn && (submitBtn.classList.contains('ajax_add_to_cart') ||
                         document.body.classList.contains('astra-woo-single-product-ajax') ||
                         form.classList.contains('cart') && window.wc_add_to_cart_params);

            if (productData && !isAjax) {
                pushDataLayer({
                    'event': 'add_to_cart',
                    'eventId': generateEventId('atc'),
                    'ecommerce': {
                        'currency': config.currency || 'USD',
                        'value': (productData.price || 0) * (productData.quantity || 1),
                        'items': [productData]
                    }
                });
                window.pixelflyPendingSingleProductCart = null;
            }
        });

        // Track variation selection
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('found_variation', function(e, variation) {
                if (variation) {
                    var baseData = getProductDataFromForm(e.target);
                    window.pixelflySelectedVariation = {
                        item_id: variation.variation_id.toString(),
                        item_name: baseData ? baseData.item_name : 'Product',
                        price: parseFloat(variation.display_price) || 0,
                        quantity: 1,
                        item_variant: variation.attributes ? Object.values(variation.attributes).join(' / ') : '',
                        item_group_id: baseData ? baseData.item_id : ''
                    };
                    log('Variation selected', window.pixelflySelectedVariation);
                }
            });

            jQuery(document).on('reset_data', function(e) {
                window.pixelflySelectedVariation = null;
            });

            // AJAX add to cart on single product page
            jQuery(document.body).on('adding_to_cart', function(e, button, data) {
                log('adding_to_cart event triggered (single product)');
                // Capture data before AJAX completes
                if (window.pixelflyPendingSingleProductCart) {
                    window.pixelflyPendingAddToCart = window.pixelflyPendingSingleProductCart;
                    window.pixelflySingleProductPending = true;
                }
            });

            // Listen specifically for WooCommerce added_to_cart on single product pages
            // This is the PRIMARY handler for AJAX add to cart (triggered by WooCommerce, Astra, etc.)
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                log('added_to_cart event triggered on single product page', {
                    isSingleProductPage: isSingleProductPage,
                    singleProductPending: window.pixelflySingleProductPending,
                    hasPendingData: !!window.pixelflyPendingSingleProductCart
                });

                // Check if this is from a single product page add to cart
                if (isSingleProductPage && window.pixelflySingleProductPending) {
                    var productData = window.pixelflyPendingSingleProductCart;

                    // If no pending data, try to get it now
                    if (!productData) {
                        var form = document.querySelector('form.cart');
                        productData = getProductDataFromForm(form);
                        if (productData) {
                            var quantityInput = form ? form.querySelector('input[name="quantity"]') : null;
                            productData.quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
                        }
                    }

                    if (productData && productData.item_id) {
                        log('Single product added_to_cart event - firing add_to_cart', productData);
                        pushDataLayer({
                            'event': 'add_to_cart',
                            'eventId': generateEventId('atc'),
                            'ecommerce': {
                                'currency': config.currency || 'USD',
                                'value': (productData.price || 0) * (productData.quantity || 1),
                                'items': [productData]
                            }
                        });
                    } else {
                        log('No product data available for single product add_to_cart');
                    }

                    window.pixelflyPendingSingleProductCart = null;
                    window.pixelflyPendingAddToCart = null;
                    window.pixelflySingleProductPending = false;
                }
            });

            // Also intercept ALL AJAX requests for single product as a FALLBACK
            // This handles custom cart plugins that don't trigger standard WooCommerce events
            jQuery(document).ajaxComplete(function(event, xhr, settings) {
                var data = settings.data;
                var url = settings.url || '';

                // Check for various add to cart patterns (standard WooCommerce + custom plugins)
                var isAddToCart = false;
                if (typeof data === 'string') {
                    isAddToCart = data.indexOf('add-to-cart=') > -1 ||
                                  data.indexOf('wc-ajax=add_to_cart') > -1 ||
                                  data.indexOf('add_to_cart') > -1 ||
                                  data.indexOf('addtocart') > -1;
                }
                if (url.indexOf('wc-ajax=add_to_cart') > -1 || url.indexOf('admin-ajax.php') > -1) {
                    // For admin-ajax.php, check if we have pending data and response looks successful
                    if (url.indexOf('admin-ajax.php') > -1 && window.pixelflySingleProductPending) {
                        isAddToCart = true;
                    }
                }

                // Only fire as fallback - with delay to ensure added_to_cart had time to fire first
                if (isAddToCart && isSingleProductPage) {
                    setTimeout(function() {
                        // Check if data is still pending (added_to_cart didn't fire)
                        if (window.pixelflyPendingSingleProductCart && window.pixelflySingleProductPending) {
                            var productData = window.pixelflyPendingSingleProductCart;
                            log('Single product AJAX complete FALLBACK - firing add_to_cart', productData);
                            pushDataLayer({
                                'event': 'add_to_cart',
                                'eventId': generateEventId('atc'),
                                'ecommerce': {
                                    'currency': config.currency || 'USD',
                                    'value': (productData.price || 0) * (productData.quantity || 1),
                                    'items': [productData]
                                }
                            });
                            window.pixelflyPendingSingleProductCart = null;
                            window.pixelflyPendingAddToCart = null;
                            window.pixelflySingleProductPending = false;
                        }
                    }, 300); // Delay to ensure added_to_cart had time (if it fires)
                }
            });
        }
    }

    // Track add to cart from WooCommerce Blocks
    function initBlocksAddToCartTracking() {
        // Listen for WooCommerce Blocks store changes
        if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
            var lastCartCount = null;
            wp.data.subscribe(function() {
                var store = wp.data.select('wc/store/cart');
                if (store) {
                    var cart = store.getCartData();
                    if (cart && cart.items) {
                        var currentCount = cart.items.length;
                        if (lastCartCount !== null && currentCount > lastCartCount) {
                            // Item was added - get the new item
                            var newItem = cart.items[cart.items.length - 1];
                            if (newItem) {
                                pushDataLayer({
                                    'event': 'add_to_cart',
                                    'eventId': generateEventId('atc'),
                                    'ecommerce': {
                                        'currency': config.currency,
                                        'value': parseFloat(newItem.prices.price) / 100 * newItem.quantity,
                                        'items': [{
                                            item_id: newItem.id.toString(),
                                            item_name: newItem.name,
                                            price: parseFloat(newItem.prices.price) / 100,
                                            quantity: newItem.quantity
                                        }]
                                    }
                                });
                            }
                        }
                        lastCartCount = currentCount;
                    }
                }
            });
        }
    }

    // =====================================================
    // REMOVE FROM CART TRACKING
    // =====================================================

    function initRemoveFromCartTracking() {
        // Classic cart page
        document.addEventListener('click', function(e) {
            var removeLink = e.target.closest('.remove_from_cart_button, .woocommerce-cart-form .product-remove a');
            if (!removeLink) return;

            var productData = getProductDataFromElement(removeLink);
            if (!productData) {
                // Get from data attributes
                var row = removeLink.closest('tr, .cart_item');
                if (row) {
                    var nameEl = row.querySelector('.product-name a');
                    var priceEl = row.querySelector('.product-price .amount');
                    productData = {
                        item_id: removeLink.getAttribute('data-product_id') || '',
                        item_name: nameEl ? nameEl.textContent.trim() : 'Unknown',
                        price: priceEl ? parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) : 0,
                        quantity: 1
                    };
                }
            }

            if (productData && productData.item_id) {
                pushDataLayer({
                    'event': 'remove_from_cart',
                    'eventId': generateEventId('rfc'),
                    'ecommerce': {
                        'currency': config.currency,
                        'value': productData.price * (productData.quantity || 1),
                        'items': [productData]
                    }
                });
            }
        });

        // WooCommerce AJAX remove
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('removed_from_cart', function(e, fragments, cart_hash, button) {
                if (window.pixelflyLastRemovedItem) {
                    pushDataLayer({
                        'event': 'remove_from_cart',
                        'eventId': generateEventId('rfc'),
                        'ecommerce': {
                            'currency': config.currency,
                            'value': window.pixelflyLastRemovedItem.price * (window.pixelflyLastRemovedItem.quantity || 1),
                            'items': [window.pixelflyLastRemovedItem]
                        }
                    });
                    window.pixelflyLastRemovedItem = null;
                }
            });
        }
    }

    // =====================================================
    // CART QUANTITY CHANGE TRACKING
    // =====================================================

    function initCartQuantityTracking() {
        var originalQuantities = {};

        // Store original quantities on page load
        function storeOriginalQuantities() {
            document.querySelectorAll('.woocommerce-cart-form .qty, .cart_item .quantity input').forEach(function(input) {
                var key = input.name || input.getAttribute('data-cart-item-key');
                if (key) {
                    originalQuantities[key] = parseInt(input.value) || 0;
                }
            });
        }

        // Check for quantity changes
        function checkQuantityChanges() {
            document.querySelectorAll('.woocommerce-cart-form .qty, .cart_item .quantity input').forEach(function(input) {
                var key = input.name || input.getAttribute('data-cart-item-key');
                if (!key) return;

                var newQty = parseInt(input.value) || 0;
                var oldQty = originalQuantities[key] || 0;

                if (newQty !== oldQty) {
                    var row = input.closest('tr, .cart_item');
                    if (!row) return;

                    var nameEl = row.querySelector('.product-name a');
                    var priceEl = row.querySelector('.product-price .amount');
                    var productData = {
                        item_id: row.getAttribute('data-product-id') || '',
                        item_name: nameEl ? nameEl.textContent.trim() : 'Unknown',
                        price: priceEl ? parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) : 0,
                        quantity: Math.abs(newQty - oldQty)
                    };

                    if (newQty > oldQty) {
                        pushDataLayer({
                            'event': 'add_to_cart',
                            'eventId': generateEventId('atc'),
                            'ecommerce': {
                                'currency': config.currency,
                                'value': productData.price * productData.quantity,
                                'items': [productData]
                            }
                        });
                    } else {
                        pushDataLayer({
                            'event': 'remove_from_cart',
                            'eventId': generateEventId('rfc'),
                            'ecommerce': {
                                'currency': config.currency,
                                'value': productData.price * productData.quantity,
                                'items': [productData]
                            }
                        });
                    }
                }
            });
        }

        // Listen for cart update button
        document.addEventListener('click', function(e) {
            if (e.target.closest('[name="update_cart"], .update-cart-button')) {
                checkQuantityChanges();
            }
        });

        // Store on load and after AJAX updates
        storeOriginalQuantities();
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_cart_totals', storeOriginalQuantities);
        }
    }

    // =====================================================
    // SELECT ITEM (PRODUCT CLICK) TRACKING
    // =====================================================

    function initSelectItemTracking() {
        document.addEventListener('click', function(e) {
            var productLink = e.target.closest('.woocommerce-loop-product__link, .wc-block-grid__product-link, .products .product a.woocommerce-LoopProduct-link');
            if (!productLink) return;

            // Check if it's a real product link (not add to cart)
            if (productLink.classList.contains('add_to_cart_button')) return;

            var productData = getProductDataFromElement(productLink);
            if (!productData) {
                var product = productLink.closest('.product, .wc-block-grid__product');
                if (product) {
                    productData = getProductDataFromElement(product);
                }
            }

            if (productData && productData.item_id) {
                // Get list name
                var listName = 'Product List';
                var listEl = productLink.closest('[data-list-name]');
                if (listEl) {
                    listName = listEl.getAttribute('data-list-name');
                } else if (document.body.classList.contains('archive')) {
                    listName = 'Category';
                } else if (document.body.classList.contains('search-results')) {
                    listName = 'Search Results';
                }

                productData.item_list_name = listName;

                pushDataLayer({
                    'event': 'select_item',
                    'eventId': generateEventId('si'),
                    'ecommerce': {
                        'currency': config.currency,
                        'items': [productData]
                    }
                });
            }
        });
    }

    // =====================================================
    // PURCHASE DUPLICATE PREVENTION
    // =====================================================

    window.pixelflyCheckPurchaseTracked = function(orderId) {
        var key = 'pixelfly_purchase_' + orderId;

        // Check localStorage
        try {
            if (localStorage.getItem(key)) {
                return true;
            }
        } catch (e) {}

        // Check cookie
        if (document.cookie.indexOf(key + '=1') !== -1) {
            return true;
        }

        return false;
    };

    window.pixelflyMarkPurchaseTracked = function(orderId) {
        var key = 'pixelfly_purchase_' + orderId;

        // Set localStorage
        try {
            localStorage.setItem(key, '1');
        } catch (e) {}

        // Set cookie (365 days)
        var expires = new Date();
        expires.setTime(expires.getTime() + 365 * 24 * 60 * 60 * 1000);
        document.cookie = key + '=1;expires=' + expires.toUTCString() + ';path=/';
    };

    // =====================================================
    // MINI CART TRACKING
    // =====================================================

    function initMiniCartTracking() {
        // Track mini-cart remove buttons
        document.addEventListener('click', function(e) {
            var removeBtn = e.target.closest('.mini_cart_item .remove, .woocommerce-mini-cart-item .remove');
            if (!removeBtn) return;

            var item = removeBtn.closest('.mini_cart_item, .woocommerce-mini-cart-item');
            if (!item) return;

            var productData = {
                item_id: removeBtn.getAttribute('data-product_id') || '',
                item_name: item.querySelector('.product-name, a:not(.remove)') ? item.querySelector('.product-name, a:not(.remove)').textContent.trim() : 'Unknown',
                price: 0,
                quantity: 1
            };

            window.pixelflyLastRemovedItem = productData;
        });
    }

    // =====================================================
    // WISHLIST TRACKING (if supported)
    // =====================================================

    function initWishlistTracking() {
        document.addEventListener('click', function(e) {
            var wishlistBtn = e.target.closest('.add_to_wishlist, .yith-wcwl-add-to-wishlist a');
            if (!wishlistBtn) return;

            var productData = getProductDataFromElement(wishlistBtn);
            if (productData && productData.item_id) {
                pushDataLayer({
                    'event': 'add_to_wishlist',
                    'eventId': generateEventId('atw'),
                    'ecommerce': {
                        'currency': config.currency,
                        'value': productData.price,
                        'items': [productData]
                    }
                });
            }
        });
    }

    // =====================================================
    // INITIALIZATION
    // =====================================================

    function init() {
        // Re-check config in case it was set after script load
        if (window.pixelflyConfig) {
            config = window.pixelflyConfig;
            debug = config.debug || false;
        }

        // Initialize Facebook cookies FIRST (before any tracking)
        // This creates _fbc from fbclid URL parameter
        initFacebookCookies();

        initAddToCartListTracking();
        initAddToCartFormTracking();
        initBlocksAddToCartTracking();
        initRemoveFromCartTracking();
        initCartQuantityTracking();
        initSelectItemTracking();
        initMiniCartTracking();
        initWishlistTracking();

        log('PixelFly WooCommerce initialized', config);
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
