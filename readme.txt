=== PixelFly for WooCommerce ===
Contributors: pixelfly
Tags: conversion tracking, meta capi, facebook pixel, ga4, server-side tracking, woocommerce
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side event tracking for Meta CAPI & GA4 via PixelFly. Includes dataLayer support and delayed purchase events for COD orders.

== Description ==

PixelFly for WooCommerce provides complete server-side event tracking for your WooCommerce store. Send conversion data to Meta CAPI and Google Analytics 4 with high accuracy and full customer data matching.

= Features =

* **Server-side tracking** - Send events directly from your server to Meta and GA4
* **Complete eCommerce events** - page_view, view_item, add_to_cart, purchase, and more
* **DataLayer support** - Full GA4-compatible dataLayer for GTM integration
* **Delayed purchase events** - Store COD orders and fire events after confirmation
* **User data collection** - Capture fbp, fbc, email, phone for enhanced matching
* **UTM parameter tracking** - Preserve marketing attribution across sessions

= Supported Events =

* `page_view` - Every page load
* `view_item` - Product page views
* `view_item_list` - Category and shop pages
* `add_to_cart` - Add to cart (including AJAX)
* `remove_from_cart` - Remove from cart
* `view_cart` - Cart page views
* `begin_checkout` - Checkout page load
* `add_shipping_info` - Shipping method selection
* `add_payment_info` - Payment method selection
* `purchase` - Order completion

= Delayed Purchase Events =

For COD (Cash on Delivery) and manual payment orders, the plugin stores purchase events and fires them when the order status changes to "Processing" or "Completed". This ensures you only track confirmed conversions.

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* PixelFly account ([pixelfly.io](https://pixelfly.io))

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/pixelfly-woocommerce`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to PixelFly > Settings
4. Enter your PixelFly API key
5. Configure delayed events settings (optional)
6. Save changes

== Frequently Asked Questions ==

= Where do I get my API key? =

Log in to your PixelFly dashboard at pixelfly.io and copy your container API key.

= Does this work with any theme? =

Yes! The plugin uses WooCommerce hooks and works with any properly coded theme.

= Can I use this with Google Tag Manager? =

Yes, enable the DataLayer option to output GA4-compatible events for GTM.

= What is delayed purchase tracking? =

For COD orders, the purchase event is stored and only fired when the order status changes to Processing or Completed. This prevents tracking unconfirmed orders.

== Screenshots ==

1. Settings page
2. Pending events management
3. DataLayer events in browser console

== Changelog ==

= 1.0.0 =
* Initial release
* Server-side tracking via PixelFly API
* Complete eCommerce event tracking
* DataLayer output for GTM
* Delayed purchase events for COD orders
* UTM parameter capture
* Admin pending events management

== Upgrade Notice ==

= 1.0.0 =
Initial release.
