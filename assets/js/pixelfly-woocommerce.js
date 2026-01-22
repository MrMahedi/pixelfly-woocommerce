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
        log('getProductDataFromForm called', { hasForm: !!form });

        // Try to find data element inside form first
        var dataEl = form ? form.querySelector('.pixelfly-product-data, input.pixelfly-product-data') : null;
        log('Searching for .pixelfly-product-data in form:', { found: !!dataEl });

        // If not found in form, search the product container or entire page
        if (!dataEl) {
            var productContainer = document.querySelector('.product, .single-product, .product-container');
            if (productContainer) {
                dataEl = productContainer.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
                log('Searching for .pixelfly-product-data in product container:', { found: !!dataEl });
            }
        }

        // Search in summary/product-info areas (common in Flatsome)
        if (!dataEl) {
            var summaryContainer = document.querySelector('.product-summary, .product-info, .summary');
            if (summaryContainer) {
                dataEl = summaryContainer.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
                log('Searching for .pixelfly-product-data in summary:', { found: !!dataEl });
            }
        }

        // Last resort - search entire page
        if (!dataEl) {
            dataEl = document.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
            log('Searching for .pixelfly-product-data on entire page:', { found: !!dataEl });
        }

        if (dataEl) {
            try {
                var jsonData = dataEl.value || dataEl.textContent || dataEl.innerText;
                log('Found product data element, raw data:', jsonData.substring(0, 100));
                var parsed = JSON.parse(jsonData);
                log('Parsed product data:', parsed);
                return parsed;
            } catch (e) {
                log('Failed to parse form product data', e.message);
            }
        }

        // Fallback: try to extract from page if no data element found
        log('No pixelfly-product-data element found, trying fallback extraction');
        return getProductDataFromPage();
    }

    // Fallback: extract product data from page elements
    function getProductDataFromPage() {
        var productData = null;
        var productId = null;

        // Try to get product ID from form
        var form = document.querySelector('form.cart');

        // Method 1: Button with name="add-to-cart" and value
        if (!productId && form) {
            var addToCartBtn = form.querySelector('button[name="add-to-cart"]');
            if (addToCartBtn && addToCartBtn.value) {
                productId = addToCartBtn.value;
                log('Product ID from button[name="add-to-cart"]:', productId);
            }
        }

        // Method 2: Hidden input with name="add-to-cart"
        if (!productId && form) {
            var addToCartInput = form.querySelector('input[name="add-to-cart"]');
            if (addToCartInput && addToCartInput.value) {
                productId = addToCartInput.value;
                log('Product ID from input[name="add-to-cart"]:', productId);
            }
        }

        // Method 3: Hidden input with name="product_id"
        if (!productId && form) {
            var productIdInput = form.querySelector('input[name="product_id"]');
            if (productIdInput && productIdInput.value) {
                productId = productIdInput.value;
                log('Product ID from input[name="product_id"]:', productId);
            }
        }

        // Method 4: Form action URL contains add-to-cart parameter
        if (!productId && form && form.action) {
            var actionMatch = form.action.match(/add-to-cart=(\d+)/);
            if (actionMatch) {
                productId = actionMatch[1];
                log('Product ID from form action:', productId);
            }
        }

        // Method 5: Body class postid-XXX
        if (!productId) {
            var bodyClasses = document.body.className.split(' ');
            for (var i = 0; i < bodyClasses.length; i++) {
                var match = bodyClasses[i].match(/postid-(\d+)/);
                if (match) {
                    productId = match[1];
                    log('Product ID from body class:', productId);
                    break;
                }
            }
        }

        // Method 6: data-product_id attribute on various elements
        if (!productId) {
            var dataProductEl = document.querySelector('[data-product_id]');
            if (dataProductEl) {
                productId = dataProductEl.getAttribute('data-product_id');
                log('Product ID from data-product_id:', productId);
            }
        }

        // Get product name from page - try multiple selectors
        var productName = '';
        var nameSelectors = [
            '.product_title',
            '.entry-title',
            'h1.product-title',
            '.product-title',
            '.woocommerce-product-details__short-description h1',
            '.product-main h1',
            '.product-info h1',
            'h1'
        ];
        for (var j = 0; j < nameSelectors.length; j++) {
            var nameEl = document.querySelector(nameSelectors[j]);
            if (nameEl && nameEl.textContent.trim()) {
                productName = nameEl.textContent.trim();
                log('Product name from', nameSelectors[j], ':', productName);
                break;
            }
        }

        // Get price - try multiple selectors
        var price = 0;
        var priceSelectors = [
            '.product .price ins .woocommerce-Price-amount',
            '.product .price > .woocommerce-Price-amount',
            '.product p.price .woocommerce-Price-amount',
            '.summary .price .woocommerce-Price-amount',
            '.product-info .price .woocommerce-Price-amount',
            '.woocommerce-Price-amount'
        ];
        for (var k = 0; k < priceSelectors.length; k++) {
            var priceEl = document.querySelector(priceSelectors[k]);
            if (priceEl) {
                var priceText = priceEl.textContent || '';
                // Remove currency symbols and thousands separators, handle both . and , as decimal
                price = parseFloat(priceText.replace(/[^\d.,]/g, '').replace(/,(?=\d{3})/g, '').replace(',', '.')) || 0;
                if (price > 0) {
                    log('Price from', priceSelectors[k], ':', price);
                    break;
                }
            }
        }

        if (productId) {
            productData = {
                item_id: productId.toString(),
                item_name: productName || 'Product',
                price: price,
                quantity: 1
            };
            log('Extracted product data from page', productData);
        } else {
            log('Could not extract product ID from page');
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

        // Detect Flatsome theme - check for Flatsome-specific indicators
        var isFlatsomeTheme = (typeof window.flatsomeVars !== 'undefined') ||
                              (typeof window.Flatsome !== 'undefined') ||
                              document.querySelector('script[src*="flatsome"]') !== null ||
                              document.querySelector('link[href*="flatsome"]') !== null;

        if (isFlatsomeTheme) {
            log('Flatsome theme detected');
        }

        // Capture click on single product add to cart button
        document.addEventListener('click', function(e) {
            // Broader selector to catch Flatsome and other themes
            var button = e.target.closest('.single_add_to_cart_button, form.cart button[type="submit"], form.cart button.button, .ast-sticky-add-to-cart .single_add_to_cart_button, .sticky-add-to-cart button');
            if (!button) return;

            // Skip if button is disabled or loading
            if (button.classList.contains('disabled') || button.classList.contains('loading')) {
                log('Button is disabled or loading, skipping');
                return;
            }

            var form = button.closest('form.cart');
            // For sticky add to cart buttons, find the main form
            if (!form && document.querySelector('form.cart')) {
                form = document.querySelector('form.cart');
            }
            if (!form) return;

            log('Add to cart button clicked', {
                buttonClass: button.className,
                formAction: form.action,
                isFlatsomeTheme: isFlatsomeTheme
            });

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

        // Listen for form submit (for non-AJAX themes like Flatsome default)
        document.addEventListener('submit', function(e) {
            var form = e.target;
            // Check if this is a cart form
            if (!form.classList.contains('cart') && !form.closest('form.cart')) return;
            if (form.closest) form = form.closest('form.cart') || form;

            log('Form submit event captured', {
                formClass: form.className,
                isFlatsomeTheme: isFlatsomeTheme
            });

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

            // Check if this is likely an AJAX submission
            var submitBtn = form.querySelector('.single_add_to_cart_button');
            var isAjax = submitBtn && submitBtn.classList.contains('ajax_add_to_cart');

            // For Astra AJAX
            if (document.body.classList.contains('astra-woo-single-product-ajax')) {
                isAjax = true;
            }

            // For Flatsome AJAX - check multiple indicators
            // Flatsome enables AJAX via flatsomeVars.ajaxAddToCartSingle or similar settings
            if (isFlatsomeTheme) {
                // Check if Flatsome AJAX add to cart is enabled
                var flatsomeAjaxEnabled = (typeof flatsomeVars !== 'undefined' && flatsomeVars.ajaxAddToCartSingle === 'yes') ||
                                          (typeof flatsomeVars !== 'undefined' && flatsomeVars.ajax_add_to_cart === 'yes') ||
                                          document.body.classList.contains('flatsome-ajax-add-to-cart');
                if (flatsomeAjaxEnabled) {
                    isAjax = true;
                    log('Flatsome AJAX mode detected');
                }
            }

            // For Flatsome - if AJAX is NOT detected, fire immediately on form submit
            if (isFlatsomeTheme && !isAjax) {
                // Flatsome default is NON-AJAX, fire immediately on form submit
                if (productData && productData.item_id) {
                    log('Flatsome form submit (non-AJAX) - firing add_to_cart', productData);
                    window.pixelflyLastAddToCartTime = Date.now();
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
                return;
            }

            // For other non-AJAX themes
            if (productData && productData.item_id && !isAjax) {
                log('Form submit (non-AJAX) - firing add_to_cart', productData);
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
            // This is the PRIMARY handler for AJAX add to cart (triggered by WooCommerce, Astra, Flatsome, etc.)
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                log('added_to_cart event triggered', {
                    isSingleProductPage: isSingleProductPage,
                    singleProductPending: window.pixelflySingleProductPending,
                    hasPendingData: !!window.pixelflyPendingSingleProductCart,
                    isFlatsomeTheme: isFlatsomeTheme,
                    hasFragments: !!fragments,
                    hasCartHash: !!cart_hash
                });

                // For single product pages - check if we have pending data
                // For Flatsome AJAX mode: if no pending data but we're on single product page,
                // the click might not have been captured, so we extract data from page
                var shouldHandle = isSingleProductPage && window.pixelflySingleProductPending;

                // Prevent duplicate firing - check if we already fired recently
                var now = Date.now();
                if (window.pixelflyLastAddToCartTime && (now - window.pixelflyLastAddToCartTime) < 1000) {
                    log('Skipping - add_to_cart fired recently (deduplication)');
                    return;
                }

                // For Flatsome AJAX mode: if we're on single product page but no pending data,
                // it might be because Flatsome prevented the click from being captured
                // In this case, extract product data from the page
                if (!shouldHandle && isSingleProductPage && isFlatsomeTheme) {
                    log('Flatsome AJAX mode - no pending data, will extract from page');
                    shouldHandle = true;
                }

                if (shouldHandle) {
                    var productData = window.pixelflyPendingSingleProductCart;

                    // If no pending data, try to get it now (important for Flatsome AJAX mode)
                    if (!productData) {
                        log('No pending data, extracting from page');
                        var form = document.querySelector('form.cart');
                        productData = getProductDataFromForm(form);
                        if (productData) {
                            var quantityInput = form ? form.querySelector('input[name="quantity"]') : null;
                            productData.quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
                        }
                    }

                    if (productData && productData.item_id) {
                        log('Single product added_to_cart event - firing add_to_cart', productData);
                        window.pixelflyLastAddToCartTime = Date.now();
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
            // This handles custom cart plugins and themes (like Flatsome) that don't trigger standard WooCommerce events
            jQuery(document).ajaxComplete(function(event, xhr, settings) {
                var data = settings.data;
                var url = settings.url || '';

                // Check for various add to cart patterns (standard WooCommerce + custom plugins + Flatsome)
                var isAddToCart = false;
                if (typeof data === 'string') {
                    isAddToCart = data.indexOf('add-to-cart=') > -1 ||
                                  data.indexOf('wc-ajax=add_to_cart') > -1 ||
                                  data.indexOf('add_to_cart') > -1 ||
                                  data.indexOf('addtocart') > -1 ||
                                  data.indexOf('product_id') > -1; // Flatsome pattern
                }
                if (url.indexOf('wc-ajax=add_to_cart') > -1 || url.indexOf('admin-ajax.php') > -1) {
                    // For admin-ajax.php, check if we have pending data and response looks successful
                    if (url.indexOf('admin-ajax.php') > -1 && window.pixelflySingleProductPending) {
                        isAddToCart = true;
                    }
                }

                // Check AJAX response for success indicators (fragments, cart_hash)
                var responseSuccess = false;
                if (xhr && xhr.responseText && window.pixelflySingleProductPending) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        // Flatsome/WooCommerce success patterns
                        responseSuccess = !!(response.fragments || response.cart_hash || response.success === true);
                        if (responseSuccess) {
                            log('AJAX response indicates successful cart update', { hasFragments: !!response.fragments, hasCartHash: !!response.cart_hash });
                        }
                    } catch (e) {
                        // Not JSON response - ignore
                    }
                }

                // Use shorter delay for Flatsome since it may not trigger added_to_cart at all
                var delayTime = isFlatsomeTheme ? 150 : 300;

                // Only fire as fallback - with delay to ensure added_to_cart had time to fire first
                if ((isAddToCart || responseSuccess) && isSingleProductPage) {
                    setTimeout(function() {
                        // Check if data is still pending (added_to_cart didn't fire)
                        // AND check deduplication timestamp
                        var now = Date.now();
                        var recentlyFired = window.pixelflyLastAddToCartTime && (now - window.pixelflyLastAddToCartTime) < 1000;

                        if (window.pixelflyPendingSingleProductCart && window.pixelflySingleProductPending && !recentlyFired) {
                            var productData = window.pixelflyPendingSingleProductCart;
                            log('Single product AJAX complete FALLBACK - firing add_to_cart (Flatsome/theme support)', productData);
                            window.pixelflyLastAddToCartTime = Date.now();
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
                    }, delayTime);
                }
            });

            // Flatsome specific: Listen for WC fragments events which Flatsome triggers after add to cart
            if (isFlatsomeTheme) {
                log('Adding Flatsome-specific event handlers');

                // Flatsome triggers wc_fragments_refreshed and wc_fragments_loaded after cart updates
                jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
                    // Check deduplication - don't fire if we already fired recently
                    var now = Date.now();
                    var recentlyFired = window.pixelflyLastAddToCartTime && (now - window.pixelflyLastAddToCartTime) < 1000;

                    if (window.pixelflyPendingSingleProductCart && window.pixelflySingleProductPending && !recentlyFired) {
                        var productData = window.pixelflyPendingSingleProductCart;
                        log('WC fragments event (Flatsome) - firing add_to_cart', productData);
                        window.pixelflyLastAddToCartTime = Date.now();
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
                });
            }
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
