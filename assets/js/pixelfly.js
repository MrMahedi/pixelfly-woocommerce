/**
 * PixelFly WooCommerce DataLayer
 *
 * Handles client-side e-commerce event tracking for GTM
 * Compatible with classic WooCommerce, WooCommerce Blocks, and all themes
 * Following Stape.io patterns for maximum compatibility
 */
(function() {
    'use strict';

    // Config will be set by wp_localize_script or inline script
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

    /**
     * Push to dataLayer with ecommerce:null clear (Stape pattern)
     */
    function pushDataLayer(data) {
        window.dataLayer = window.dataLayer || [];
        // Clear ecommerce object before push (GA4 best practice)
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push(data);
        log('Event pushed:', data.event, data);
    }

    // =====================================================
    // COOKIE HANDLING
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

    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    }

    /**
     * Initialize Facebook cookies (_fbc from fbclid)
     */
    function initFacebookCookies() {
        var urlParams = new URLSearchParams(window.location.search);
        var fbclid = urlParams.get('fbclid');

        if (fbclid && !getCookie('_fbc')) {
            var fbc = 'fb.1.' + Date.now() + '.' + fbclid;
            setCookie('_fbc', fbc, 90);
            log('Created _fbc cookie from fbclid:', fbc);

            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ 'fbc': fbc });
        }

        var currentFbc = getCookie('_fbc');
        var currentFbp = getCookie('_fbp');
        if (currentFbc || currentFbp) {
            log('Facebook cookies:', { fbp: currentFbp, fbc: currentFbc });
        }
    }

    // =====================================================
    // PRODUCT DATA EXTRACTION (Following Stape pattern)
    // =====================================================

    /**
     * Get item data from data attributes (data-pf_* pattern)
     * Similar to Stape's getGtmItemData function
     *
     * @param {Object|DOMStringMap} dataset - Element dataset or plain object
     * @returns {Object} - Product data object
     */
    function getPixelflyItemData(dataset) {
        var result = {};
        for (var key in dataset) {
            if (key.indexOf('pf_') === 0) {
                var itemKey = key.replace('pf_', '');
                result[itemKey] = dataset[key];
            }
        }
        return result;
    }

    /**
     * Get custom item data from data attributes (data-custom_pf_* pattern)
     *
     * @param {Object|DOMStringMap} dataset - Element dataset or plain object
     * @returns {Object} - Custom data object
     */
    function getCustomItemData(dataset) {
        var result = {};
        for (var key in dataset) {
            if (key.indexOf('custom_pf_') === 0) {
                var itemKey = key.replace('custom_pf_', '');
                result[itemKey] = dataset[key];
            }
        }
        return result;
    }

    /**
     * Filter and convert price to proper format
     *
     * @param {Object} item - Product item
     * @returns {Object} - Item with properly formatted price
     */
    function filterItemPrice(item) {
        if (typeof item.price === 'string') {
            item.price = parseFloat(item.price);
            if (isNaN(item.price)) {
                item.price = 0;
            }
        } else if (typeof item.price !== 'number') {
            item.price = 0;
        }
        item.price = parseFloat(item.price.toFixed(2));
        return item;
    }

    /**
     * Convert form inputs to object (Stape pattern)
     *
     * @param {NodeList|jQuery} $els - Form input elements
     * @returns {Object} - Key-value object
     */
    function convertInputsToObject($els) {
        var data = {};
        if (!$els || !$els.length) {
            return data;
        }

        for (var i = 0; i < $els.length; i++) {
            var el = $els[i];
            var name = el.getAttribute('name') || el.getAttribute('data-name');
            if (name) {
                data[name] = el.value;
            }
        }
        return data;
    }

    /**
     * Get product data from DOM element (backward compatible)
     */
    function getProductDataFromElement(element) {
        // First try new data attributes pattern (data-pf_*)
        var dataset = element.dataset || {};
        if (dataset.pf_item_id) {
            return getPixelflyItemData(dataset);
        }

        // Try parent container
        var parent = element.closest('.product, .wc-block-grid__product, li.product');
        if (parent) {
            var parentDataset = parent.dataset || {};
            if (parentDataset.pf_item_id) {
                return getPixelflyItemData(parentDataset);
            }
        }

        // Fallback: try old .pixelfly-product-data pattern
        var dataEl = element.querySelector('.pixelfly-product-data');
        if (!dataEl && parent) {
            dataEl = parent.querySelector('.pixelfly-product-data');
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

    /**
     * Get product data from form (single product page)
     */
    function getProductDataFromForm(form) {
        log('getProductDataFromForm called', { hasForm: !!form });

        // First try hidden inputs with name^=pf_ (new pattern)
        if (form) {
            var pfInputs = form.querySelectorAll('[name^="pf_"]');
            if (pfInputs.length > 0) {
                var inputData = convertInputsToObject(pfInputs);
                var result = getPixelflyItemData(inputData);
                if (result.item_id) {
                    log('Got product data from pf_ inputs:', result);
                    return result;
                }
            }
        }

        // Try old .pixelfly-product-data pattern
        var dataEl = form ? form.querySelector('.pixelfly-product-data, input.pixelfly-product-data') : null;

        if (!dataEl) {
            var productContainer = document.querySelector('.product, .single-product, .product-container');
            if (productContainer) {
                dataEl = productContainer.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
            }
        }

        if (!dataEl) {
            var summaryContainer = document.querySelector('.product-summary, .product-info, .summary');
            if (summaryContainer) {
                dataEl = summaryContainer.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
            }
        }

        if (!dataEl) {
            dataEl = document.querySelector('.pixelfly-product-data, input.pixelfly-product-data');
        }

        if (dataEl) {
            try {
                var jsonData = dataEl.value || dataEl.textContent || dataEl.innerText;
                var parsed = JSON.parse(jsonData);
                log('Parsed product data:', parsed);
                return parsed;
            } catch (e) {
                log('Failed to parse form product data', e.message);
            }
        }

        log('No pixelfly-product-data element found, trying fallback extraction');
        return getProductDataFromPage();
    }

    /**
     * Fallback: extract product data from page elements
     */
    function getProductDataFromPage() {
        var productData = null;
        var productId = null;

        var form = document.querySelector('form.cart');

        if (!productId && form) {
            var addToCartBtn = form.querySelector('button[name="add-to-cart"]');
            if (addToCartBtn && addToCartBtn.value) {
                productId = addToCartBtn.value;
            }
        }

        if (!productId && form) {
            var addToCartInput = form.querySelector('input[name="add-to-cart"]');
            if (addToCartInput && addToCartInput.value) {
                productId = addToCartInput.value;
            }
        }

        if (!productId && form) {
            var productIdInput = form.querySelector('input[name="product_id"]');
            if (productIdInput && productIdInput.value) {
                productId = productIdInput.value;
            }
        }

        if (!productId && form && form.action) {
            var actionMatch = form.action.match(/add-to-cart=(\d+)/);
            if (actionMatch) {
                productId = actionMatch[1];
            }
        }

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

        if (!productId) {
            var dataProductEl = document.querySelector('[data-product_id]');
            if (dataProductEl) {
                productId = dataProductEl.getAttribute('data-product_id');
            }
        }

        var productName = '';
        var nameSelectors = [
            '.product_title', '.entry-title', 'h1.product-title', '.product-title',
            '.woocommerce-product-details__short-description h1', '.product-main h1',
            '.product-info h1', 'h1'
        ];
        for (var j = 0; j < nameSelectors.length; j++) {
            var nameEl = document.querySelector(nameSelectors[j]);
            if (nameEl && nameEl.textContent.trim()) {
                productName = nameEl.textContent.trim();
                break;
            }
        }

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
                price = parseFloat(priceText.replace(/[^\d.,]/g, '').replace(/,(?=\d{3})/g, '').replace(',', '.')) || 0;
                if (price > 0) {
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
        }

        return productData;
    }

    // =====================================================
    // ADD TO CART TRACKING (Stape pattern)
    // =====================================================

    /**
     * Push add_to_cart event to dataLayer
     *
     * @param {Object|Array} item - Product item(s)
     */
    function pushAddToCart(item) {
        var items = Array.isArray(item) ? item : [item];
        var value = 0;

        items = items.map(function(itemLoop, index) {
            itemLoop.index = index + 1;
            itemLoop.quantity = itemLoop.quantity ? parseInt(itemLoop.quantity, 10) : 1;
            itemLoop = filterItemPrice(itemLoop);
            value += parseFloat(itemLoop.price) * itemLoop.quantity;
            return itemLoop;
        });

        pushDataLayer({
            'event': 'add_to_cart',
            'eventId': generateEventId('atc'),
            'ecommerce': {
                'currency': config.currency || 'USD',
                'value': parseFloat(value.toFixed(2)),
                'items': items
            }
        });
    }

    /**
     * Push remove_from_cart event to dataLayer
     *
     * @param {Object} item - Product item
     */
    function pushRemoveFromCart(item) {
        item.quantity = item.quantity || 1;
        item = filterItemPrice(item);

        pushDataLayer({
            'event': 'remove_from_cart',
            'eventId': generateEventId('rfc'),
            'ecommerce': {
                'currency': config.currency || 'USD',
                'value': parseFloat((item.price * item.quantity).toFixed(2)),
                'items': [item]
            }
        });
    }

    /**
     * Track add to cart from product lists (AJAX)
     */
    function initAddToCartListTracking() {
        // Handle click on add to cart buttons in product lists
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.add_to_cart_button:not(.product_type_variable):not(.product_type_grouped):not(.single_add_to_cart_button)');
            if (!button) return;

            // Check for data-pf_* attributes on button (new pattern)
            var dataset = button.dataset || {};
            var productData = null;

            if (dataset.pf_item_id) {
                productData = getPixelflyItemData(dataset);
                log('Got product data from button data-pf_* attributes:', productData);
            }

            // Try WooCommerce Blocks pattern
            if (!productData) {
                var $el = button.closest('.wc-block-grid__product');
                if ($el) {
                    var blockDataset = $el.dataset || {};
                    if (blockDataset.pf_item_id) {
                        productData = getPixelflyItemData(blockDataset);
                        log('Got product data from Blocks container:', productData);
                    }
                }
            }

            // Fallback to old pattern
            if (!productData) {
                productData = getProductDataFromElement(button);
            }

            // Last resort: WooCommerce default data attributes
            if (!productData && dataset.product_id) {
                var productContainer = button.closest('.product, .wc-block-grid__product, li.product');
                var productName = 'Product';
                var price = 0;

                if (productContainer) {
                    var titleEl = productContainer.querySelector('.woocommerce-loop-product__title, .wc-block-grid__product-title, h2, h3');
                    if (titleEl) {
                        productName = titleEl.textContent.trim();
                    }
                    var priceEl = productContainer.querySelector('.price ins .amount, .price > .amount');
                    if (priceEl) {
                        price = parseFloat(priceEl.textContent.replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
                    }
                }

                productData = {
                    item_id: dataset.product_id,
                    item_name: productName,
                    price: price,
                    quantity: parseInt(dataset.quantity) || 1
                };
            }

            if (productData && productData.item_id) {
                window.pixelflyPendingAddToCart = productData;
                window.pixelflyListProductPending = true;
                window.pixelflySingleProductPending = false;
                log('Add to cart click captured (list)', productData);
            }
        }, true);

        // Listen for WooCommerce AJAX add to cart success
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                log('added_to_cart event triggered (list handler)');

                if (window.pixelflySingleProductPending) {
                    log('Skipping list handler - single product pending');
                    return;
                }

                if (!window.pixelflyListProductPending && !window.pixelflyPendingAddToCart) {
                    log('Skipping list handler - no pending data');
                    return;
                }

                var productData = window.pixelflyPendingAddToCart;

                if (!productData && button && button.length) {
                    var btn = button[0] || button;
                    if (btn.classList && btn.classList.contains('single_add_to_cart_button')) {
                        return;
                    }

                    // Try data-pf_* attributes first
                    var btnDataset = btn.dataset || {};
                    if (btnDataset.pf_item_id) {
                        productData = getPixelflyItemData(btnDataset);
                    } else {
                        productData = getProductDataFromElement(btn);
                    }

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
                    pushAddToCart(productData);
                } else {
                    log('No product data available for add_to_cart event');
                }

                window.pixelflyPendingAddToCart = null;
                window.pixelflyListProductPending = false;
            });
        }
    }

    /**
     * Track add to cart from single product page
     */
    function initAddToCartFormTracking() {
        var isSingleProductPage = document.body.classList.contains('single-product') ||
                                  document.querySelector('form.cart .single_add_to_cart_button');

        var isFlatsomeTheme = (typeof window.flatsomeVars !== 'undefined') ||
                              (typeof window.Flatsome !== 'undefined') ||
                              document.querySelector('script[src*="flatsome"]') !== null;

        // Capture click on single product add to cart button
        document.addEventListener('click', function(e) {
            var button = e.target.closest('.single_add_to_cart_button, form.cart button[type="submit"], form.cart button.button');
            if (!button) return;

            if (button.classList.contains('disabled') || button.classList.contains('loading')) {
                return;
            }

            var form = button.closest('form.cart');
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
                window.pixelflyPendingSingleProductCart = productData;
                window.pixelflyPendingAddToCart = productData;
                window.pixelflySingleProductPending = true;
                log('Single product add to cart click captured', productData);
            }
        }, true);

        // Listen for form submit (for non-AJAX themes)
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form.classList.contains('cart') && !form.closest('form.cart')) return;
            if (form.closest) form = form.closest('form.cart') || form;

            var submitBtn = form.querySelector('.single_add_to_cart_button');
            var isAjax = submitBtn && submitBtn.classList.contains('ajax_add_to_cart');

            if (document.body.classList.contains('astra-woo-single-product-ajax')) {
                isAjax = true;
            }

            if (isFlatsomeTheme) {
                var flatsomeAjaxEnabled = (typeof flatsomeVars !== 'undefined' && flatsomeVars.ajaxAddToCartSingle === 'yes');
                if (flatsomeAjaxEnabled) {
                    isAjax = true;
                }
            }

            // For non-AJAX, fire immediately
            if (!isAjax) {
                var productData = window.pixelflyPendingSingleProductCart;
                if (!productData) {
                    productData = getProductDataFromForm(form);
                    var quantityInput = form.querySelector('input[name="quantity"]');
                    if (productData) {
                        productData.quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
                    }
                }

                if (productData && productData.item_id) {
                    log('Form submit (non-AJAX) - firing add_to_cart', productData);
                    window.pixelflyLastAddToCartTime = Date.now();
                    pushAddToCart(productData);
                    window.pixelflyPendingSingleProductCart = null;
                    window.pixelflyPendingAddToCart = null;
                    window.pixelflySingleProductPending = false;
                }
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

            jQuery(document).on('reset_data', function() {
                window.pixelflySelectedVariation = null;
            });

            // AJAX add to cart on single product page
            jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
                if (!isSingleProductPage) return;

                var now = Date.now();
                if (window.pixelflyLastAddToCartTime && (now - window.pixelflyLastAddToCartTime) < 1000) {
                    log('Skipping - add_to_cart fired recently (deduplication)');
                    return;
                }

                var shouldHandle = window.pixelflySingleProductPending;

                if (!shouldHandle && isFlatsomeTheme) {
                    shouldHandle = true;
                }

                if (shouldHandle) {
                    var productData = window.pixelflyPendingSingleProductCart;

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
                        window.pixelflyLastAddToCartTime = Date.now();
                        pushAddToCart(productData);
                    }

                    window.pixelflyPendingSingleProductCart = null;
                    window.pixelflyPendingAddToCart = null;
                    window.pixelflySingleProductPending = false;
                }
            });
        }
    }

    /**
     * Track add to cart from WooCommerce Blocks
     */
    function initBlocksAddToCartTracking() {
        if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
            var lastCartCount = null;
            wp.data.subscribe(function() {
                var store = wp.data.select('wc/store/cart');
                if (store) {
                    var cart = store.getCartData();
                    if (cart && cart.items) {
                        var currentCount = cart.items.length;
                        if (lastCartCount !== null && currentCount > lastCartCount) {
                            var newItem = cart.items[cart.items.length - 1];
                            if (newItem) {
                                pushAddToCart({
                                    item_id: newItem.id.toString(),
                                    item_name: newItem.name,
                                    price: parseFloat(newItem.prices.price) / 100,
                                    quantity: newItem.quantity
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
    // REMOVE FROM CART TRACKING (Stape pattern)
    // =====================================================

    function initRemoveFromCartTracking() {
        // Mini-cart remove (removed_from_cart event)
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('removed_from_cart', function(e, fragments, cart_hash, $thisbutton) {
                if (!$thisbutton || !$thisbutton.length) {
                    return;
                }

                var btnDataset = $thisbutton.data() || {};

                // Check for data-pf_* attributes (new pattern)
                if (btnDataset.pf_item_id) {
                    var productData = getPixelflyItemData(btnDataset);
                    pushRemoveFromCart(productData);
                    return;
                }

                // Fallback to WooCommerce default attributes
                if (btnDataset.product_id) {
                    pushRemoveFromCart({
                        item_id: btnDataset.product_id.toString(),
                        item_name: btnDataset.pf_item_name || 'Product',
                        price: parseFloat(btnDataset.pf_price) || 0,
                        quantity: parseInt(btnDataset.pf_quantity) || 1
                    });
                }
            });
        }

        // Cart page remove button click
        document.addEventListener('click', function(e) {
            var removeLink = e.target.closest('.woocommerce-cart-form .product-remove > a, .remove_from_cart_button');
            if (!removeLink) return;

            var dataset = removeLink.dataset || {};

            // Check for data-pf_* attributes (new pattern)
            if (dataset.pf_item_id) {
                var productData = getPixelflyItemData(dataset);
                pushRemoveFromCart(productData);
                return;
            }

            // Fallback to extracting from row
            var row = removeLink.closest('tr, .cart_item');
            if (row) {
                var nameEl = row.querySelector('.product-name a');
                var priceEl = row.querySelector('.product-price .amount');
                pushRemoveFromCart({
                    item_id: removeLink.getAttribute('data-product_id') || '',
                    item_name: nameEl ? nameEl.textContent.trim() : 'Unknown',
                    price: priceEl ? parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) : 0,
                    quantity: 1
                });
            }
        });
    }

    // =====================================================
    // CART QUANTITY CHANGE TRACKING (Stape pattern)
    // =====================================================

    function initCartQuantityTracking() {
        function changeCartQty() {
            document.querySelectorAll('.product-quantity input.qty').forEach(function(el) {
                var originalValue = parseInt(el.defaultValue) || 0;
                var currentValue = parseInt(el.value);
                if (isNaN(currentValue)) {
                    currentValue = originalValue;
                }

                if (originalValue !== currentValue) {
                    var elCartItem = el.closest('.cart_item');
                    var elDataset = elCartItem && elCartItem.querySelector('.remove');
                    if (!elDataset) {
                        return;
                    }

                    var dataset = elDataset.dataset || {};
                    var item = {};

                    // Check for data-pf_* attributes
                    if (dataset.pf_item_id) {
                        item = getPixelflyItemData(dataset);
                    } else if (dataset.product_id) {
                        item = {
                            item_id: dataset.product_id.toString(),
                            item_name: 'Product',
                            price: 0
                        };
                    }

                    if (originalValue < currentValue) {
                        item.quantity = currentValue - originalValue;
                        pushAddToCart(item);
                    }
                    // Note: decrease handled by remove button click
                }
            });
        }

        // Listen for cart update button
        document.addEventListener('click', function(e) {
            if (e.target.closest('[name="update_cart"], .update-cart-button')) {
                changeCartQty();
            }
        });

        document.addEventListener('keypress', function(e) {
            if (e.target.closest('.woocommerce-cart-form input[type="number"]')) {
                // Delay to capture the updated value
                setTimeout(changeCartQty, 100);
            }
        });
    }

    // =====================================================
    // SELECT ITEM (PRODUCT CLICK) TRACKING
    // =====================================================

    function initSelectItemTracking() {
        document.addEventListener('click', function(e) {
            var productLink = e.target.closest('.woocommerce-loop-product__link, .wc-block-grid__product-link, .products .product a.woocommerce-LoopProduct-link');
            if (!productLink) return;

            if (productLink.classList.contains('add_to_cart_button')) return;

            var productData = null;
            var product = productLink.closest('.product, .wc-block-grid__product');

            // Check for data-pf_* attributes on product container
            if (product) {
                var dataset = product.dataset || {};
                if (dataset.pf_item_id) {
                    productData = getPixelflyItemData(dataset);
                }
            }

            // Fallback to old pattern
            if (!productData) {
                productData = getProductDataFromElement(productLink);
            }

            if (productData && productData.item_id) {
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

        try {
            if (localStorage.getItem(key)) {
                return true;
            }
        } catch (e) {}

        if (document.cookie.indexOf(key + '=1') !== -1) {
            return true;
        }

        return false;
    };

    window.pixelflyMarkPurchaseTracked = function(orderId) {
        var key = 'pixelfly_purchase_' + orderId;

        try {
            localStorage.setItem(key, '1');
        } catch (e) {}

        var expires = new Date();
        expires.setTime(expires.getTime() + 365 * 24 * 60 * 60 * 1000);
        document.cookie = key + '=1;expires=' + expires.toUTCString() + ';path=/';
    };

    // =====================================================
    // WISHLIST TRACKING
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
                        'value': productData.price || 0,
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
        if (window.pixelflyConfig) {
            config = window.pixelflyConfig;
            debug = config.debug || false;
        }

        // Initialize Facebook cookies FIRST
        initFacebookCookies();

        initAddToCartListTracking();
        initAddToCartFormTracking();
        initBlocksAddToCartTracking();
        initRemoveFromCartTracking();
        initCartQuantityTracking();
        initSelectItemTracking();
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
