=== PixelFly – Server Side Tracking | GTM DataLayer | Delayed Purchase | Consent V2 | Custom Loader ===
Contributors: pixelfly
Tags: server side tracking, sgtm, gtm datalayer, conversion tracking, consent mode, meta capi, facebook pixel, ga4, delayed purchase, ad blocker bypass
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side conversion tracking via sGTM or proxy, GTM DataLayer events, delayed purchase events for COD orders, Consent Mode V2, and custom script loader to bypass ad blockers.

== Description ==

PixelFly provides complete server-side conversion tracking and GTM DataLayer integration. Send accurate conversion data to Google Ads, Meta (Facebook), TikTok, and more — whether through server-side GTM (sGTM) or PixelFly's proxy infrastructure.

= Key Features =

* **Server-side tracking** — Send events via sGTM (GA4 Measurement Protocol) or PixelFly proxy to Meta CAPI, GA4, TikTok, etc.
* **GTM DataLayer** — Full GA4-compatible dataLayer with automatic GTM script injection
* **COD Order Protection** — Hold COD in PixelFly dashboard via GTM/sGTM; legacy delayed mode still available
* **Consent Mode V2** — Built-in cookie consent banner with Google Consent Mode V2 support for GDPR/CCPA compliance
* **Custom script loader** — Load GTM and tracking scripts through your own domain to bypass ad blockers
* **Enhanced conversions** — SHA-256 hashed user data (email, phone, name, address) for improved matching
* **Complete eCommerce events** — page_view, view_item, add_to_cart, purchase, and more
* **UTM parameter tracking** — Preserve marketing attribution across sessions

= Supported Events =

* `page_view` — Every page load
* `view_item` — Product page views
* `view_item_list` — Category and shop pages
* `add_to_cart` — Add to cart (including AJAX)
* `remove_from_cart` — Remove from cart
* `view_cart` — Cart page views
* `begin_checkout` — Checkout page load
* `add_shipping_info` — Shipping method selection
* `add_payment_info` — Payment method selection
* `purchase` — Order completion

= COD Order Protection (v1.2.0+) =

For Cash on Delivery orders, choose **GTM / sGTM** mode in PixelFly → COD Order Protection settings. The plugin pushes `purchase` + `payment_method` to dataLayer; your server GTM tag holds the order at `/cod/hold`. Approve and fire from the PixelFly dashboard or via webhook when WooCommerce status changes.

See the full guide: https://pixelfly.io/guides/cod-offline-conversions-server-gtm

= Delayed Purchase Events (legacy) =

For COD (Cash on Delivery) and manual payment orders, the plugin can store purchase events locally and fire them when the order status changes to "Processing" or "Completed". Use **Legacy** mode only for existing stores not yet migrated to COD Order Protection.

= Consent Mode V2 =

Display a customizable cookie consent banner and implement Google Consent Mode V2. Controls `analytics_storage`, `ad_storage`, `ad_user_data`, and `ad_personalization` signals for GDPR and CCPA compliance.

= Custom Script Loader =

Load GTM and tracking scripts through your PixelFly custom domain (e.g., t.yourstore.com) to bypass ad blockers and improve tracking accuracy.

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 7.4 or higher
* PixelFly account ([pixelfly.io](https://pixelfly.io))

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/pixelfly-gtm-server-side-tracking`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to PixelFly > Settings
4. Enter your PixelFly API key or configure sGTM settings
5. Enable DataLayer and set your GTM container ID (optional)
6. Configure delayed events, consent mode, and custom loader as needed
7. Save changes

== Frequently Asked Questions ==

= Where do I get my API key? =

Log in to your PixelFly dashboard at pixelfly.io and copy your container API key.

= What is the difference between PixelFly (Proxy) and sGTM? =

PixelFly Proxy sends events through PixelFly's Cloudflare Worker infrastructure. sGTM sends events via GA4 Measurement Protocol to your server-side Google Tag Manager, which distributes to all configured tags (Google Ads, Meta, TikTok, etc.).

= Does this work with any theme? =

Yes! The plugin uses WooCommerce hooks and works with any properly coded theme.

= Can I use this with Google Tag Manager? =

Yes, enable the DataLayer option to output GA4-compatible events for GTM. You can also use the Custom Loader to inject GTM through your own domain.

= What is delayed purchase tracking? =

For COD orders, hold the purchase until confirmed. Use **COD Order Protection (GTM mode)** in v1.2.0+ with the PixelFly dashboard, or **Legacy** mode to store events in WordPress.

= Does Consent Mode V2 work with sGTM? =

Yes. Consent Mode V2 operates at the browser level — consent signals are carried with GTM events and respected by sGTM tags.

== Screenshots ==

1. Settings page — API Configuration
2. DataLayer settings with GTM injection
3. Delayed purchase events management
4. Consent Mode V2 banner configuration
5. Custom script loader setup

== Changelog ==

= 1.3.1 =
* COD Order Protection: master On/Off switch; handling modes (GTM / plugin hold / legacy) only show when enabled

= 1.2.1 =
* dataLayer purchase includes gclid and fbclid (order meta + cookie fallback) for sGTM COD hold

= 1.2.0 =
* COD Order Protection: GTM/sGTM mode, plugin hold backup, legacy delayed mode
* dataLayer purchase includes payment_method
* Optional auto-confirm webhook to /cod/webhook

= 1.1.0 =
* Added sGTM as firing method for delayed purchase events (GA4 Measurement Protocol)
* Added Consent Mode V2 with customizable cookie consent banner
* Added Custom Script Loader for ad blocker bypass
* Added Enhanced Conversions support (hashed user data)
* Added sGTM connection testing

= 1.0.0 =
* Initial release
* Server-side tracking via PixelFly API
* Complete eCommerce event tracking
* DataLayer output for GTM
* Delayed purchase events for COD orders
* UTM parameter capture
* Admin pending events management

== Upgrade Notice ==

= 1.3.1 =
COD tab now has Enable On/Off. Turn off to fire purchases immediately; turn on to choose GTM / plugin hold / legacy.

= 1.2.0 =
COD Order Protection: switch to GTM/sGTM mode for new PixelFly held-events flow. See https://pixelfly.io/guides/cod-offline-conversions-server-gtm

= 1.1.0 =
Major update: sGTM support, Consent Mode V2, Custom Script Loader, and Enhanced Conversions.

= 1.0.0 =
Initial release.
