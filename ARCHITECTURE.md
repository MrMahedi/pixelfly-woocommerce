# PixelFly WooCommerce Plugin - Architecture & Data Flow

**Version:** 1.1.0
**Last Updated:** January 22, 2026

## Overview

PixelFly for WooCommerce is a comprehensive e-commerce tracking plugin that sends events to:
1. **Google Tag Manager (GTM)** - via browser-side `dataLayer` pushes
2. **PixelFly API** - server-side, ONLY for delayed purchase events (COD orders)

---

## Plugin Structure

```
pixelfly-woocommerce/
├── pixelfly-woocommerce.php          # Main plugin file, initialization
├── includes/
│   ├── class-pixelfly-admin.php      # Admin settings & AJAX handlers
│   ├── class-pixelfly-api.php        # PixelFly API client (HTTP requests)
│   ├── class-pixelfly-consent.php    # Consent Mode V2 (GDPR/CCPA) [NEW v1.1.0]
│   ├── class-pixelfly-custom-loader.php # Custom Loader (ad blocker bypass) [NEW v1.1.0]
│   ├── class-pixelfly-datalayer.php  # GTM dataLayer output (browser-side)
│   ├── class-pixelfly-delayed.php    # Delayed purchase events (COD)
│   ├── class-pixelfly-events.php     # Event data builders
│   ├── class-pixelfly-tracker.php    # Server-side tracker (legacy)
│   ├── class-pixelfly-user-data.php  # User data collection & hashing
│   └── class-pixelfly-utm-capture.php # UTM parameter capture
├── assets/js/
│   └── pixelfly-woocommerce.js       # Client-side tracking (AJAX events)
├── admin/
│   ├── views/
│   │   ├── settings-page.php         # Admin settings UI
│   │   └── pending-events.php        # Pending events management
│   ├── css/admin.css
│   └── js/admin.js
└── ARCHITECTURE.md                   # This file
```

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           USER ACTIONS                                       │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        WOOCOMMERCE EVENTS                                    │
│  • View Product  • Add to Cart  • View Cart  • Checkout  • Purchase         │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
                    ▼                               ▼
┌──────────────────────────────┐    ┌──────────────────────────────┐
│     PHP (Server-Side)        │    │    JavaScript (Browser)       │
│   PixelFly_DataLayer         │    │  pixelfly-woocommerce.js      │
│                              │    │                              │
│  • Hooks into WC actions     │    │  • Listens for AJAX events   │
│  • Outputs inline <script>   │    │  • Captures add_to_cart      │
│  • Pushes to dataLayer       │    │  • Pushes to dataLayer       │
└──────────────────────────────┘    └──────────────────────────────┘
                    │                               │
                    └───────────────┬───────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         window.dataLayer                                     │
│                    (Browser-side GTM DataLayer)                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     GOOGLE TAG MANAGER (GTM)                                 │
│                                                                              │
│   GTM processes events and sends to:                                        │
│   • Google Analytics 4 (GA4)                                                │
│   • Meta/Facebook Pixel                                                     │
│   • TikTok Pixel                                                            │
│   • Google Ads                                                              │
│   • Other configured tags                                                   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Delayed Purchase Events Flow (COD/Manual Payments)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    COD ORDER PLACED                                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                 PixelFly_Delayed::maybe_store_pending_event()                │
│                                                                              │
│   Hook: woocommerce_checkout_order_processed                                 │
│   Action: Store event data in wp_pixelfly_pending_events table              │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     DATABASE TABLE                                           │
│                 wp_pixelfly_pending_events                                   │
│                                                                              │
│   Columns: id, order_id, event_data (JSON), status, fired_at, created_at    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ (Later, when order status changes)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│              PixelFly_Delayed::maybe_fire_pending_event()                    │
│                                                                              │
│   Hook: woocommerce_order_status_changed                                     │
│   Trigger: Status changes to 'processing' or 'completed'                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                      PixelFly_API::send_event()                              │
│                                                                              │
│   HTTP POST to: https://track.pixelfly.io/e                                  │
│   Headers: X-PF-Key: {api_key}                                               │
│   Body: JSON event data                                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                      PIXELFLY SERVER                                         │
│                                                                              │
│   PixelFly processes and forwards to:                                        │
│   • Meta Conversions API (CAPI)                                              │
│   • Google Analytics Measurement Protocol                                    │
│   • Other server-side destinations                                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Events Tracked

### Browser Events (via dataLayer to GTM)

| Event | Trigger | Data |
|-------|---------|------|
| `page_view` | Every page load | page_title, page_location, page_type |
| `view_item` | Single product page | Product data, price, categories |
| `view_item_list` | Shop/Category pages | Product array with positions |
| `select_item` | Product click in list | Product data, list name |
| `add_to_cart` | Add to cart (AJAX/form) | Product, quantity, value |
| `remove_from_cart` | Remove from cart | Product, quantity, value |
| `view_cart` | Cart page | All cart items, totals |
| `begin_checkout` | Checkout page | Cart items, value |
| `add_shipping_info` | Shipping selected | Shipping method, items |
| `add_payment_info` | Payment selected | Payment method, items |
| `purchase` | Thank you page | Full order data, customer info |

### Server-Side Events (via PixelFly API)

| Event | Trigger | When |
|-------|---------|------|
| `purchase` | Order status change | COD orders when status → processing/completed |

---

## Class Responsibilities

### PixelFly_WooCommerce (Main Plugin Class)
- Singleton pattern initialization
- WooCommerce dependency check
- Load all includes
- Plugin activation/deactivation (create DB tables)

### PixelFly_Admin
- Admin menu registration
- Settings page rendering
- Settings registration (register_setting)
- AJAX handlers for test connection, fire events

### PixelFly_DataLayer
- GTM script injection (head & body)
- dataLayer initialization with page/user/cart data
- All e-commerce event output via inline `<script>` tags
- Event deduplication (static $fired_events array)
- User role exclusion check

### PixelFly_Events
- Static helper class for building event data
- `build_product_data()` - Product info with categories, variants
- `build_cart_data()` - Cart items and totals
- `build_purchase_data()` - Full order data with customer info
- `build_customer_data()` - Logged-in user info with order history
- `generate_event_id()` - Unique event ID generation

### PixelFly_Delayed
- Store pending events for COD orders
- Fire events when order status changes
- Database operations (CRUD for pending events)
- Manual fire/delete from admin UI

### PixelFly_API
- HTTP client for PixelFly endpoint
- `send_event()` - POST request with API key header
- `test_connection()` - Verify API connectivity
- Event logging to database (optional)

### PixelFly_User_Data
- User data collection (email, phone, address)
- SHA256 hashing for enhanced conversions
- Extract data from logged-in user or order

### PixelFly_UTM_Capture
- Capture UTM parameters from URL (utm_source, utm_medium, utm_campaign, utm_content, utm_term)
- Capture Click IDs (fbclid, gclid, ttclid, li_fat_id, sccid, msclkid)
- Store in session/cookie for persistence across pages
- Add hidden fields in checkout form for submission
- Attach to order meta on checkout for delayed event context

### PixelFly_User_Data
- Cookie capture: fbp, fbc, client_id, session_id (browser identifiers)
- Auto-converts fbclid URL param to _fbc cookie (90-day expiry)
- User data collection for enhanced matching (email, phone, name, address)
- SHA-256 hashing for PII fields
- Supports logged-in users and guest checkout data extraction

### pixelfly-woocommerce.js (Client-Side)
- AJAX add to cart tracking (archive pages)
- Single product AJAX add to cart
- WooCommerce Blocks compatibility
- Remove from cart tracking
- Cart quantity change tracking
- Wishlist tracking (if plugin active)
- Duplicate purchase prevention (localStorage + cookie)
- **Theme-agnostic fallbacks:** DOM extraction when `.pixelfly-product-data` not found
- **Custom cart plugin support:** `ajaxComplete` fallback for `admin-ajax.php`
- **Event deduplication flags:** `pixelflySingleProductPending` / `pixelflyListProductPending`

---

## Settings

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Enable Tracking | `pixelfly_enabled` | true | Master switch |
| API Key | `pixelfly_api_key` | '' | PixelFly API key |
| Endpoint | `pixelfly_endpoint` | track.pixelfly.io/e | API URL |
| Enable DataLayer | `pixelfly_datalayer_enabled` | true | GTM integration |
| GTM Container ID | `pixelfly_gtm_container_id` | '' | Auto-inject GTM |
| **Custom Loader** | | | |
| Enable Custom Loader | `pixelfly_custom_loader_enabled` | false | Load GTM via proxy |
| Custom Domain | `pixelfly_custom_loader_domain` | '' | First-party domain (e.g., t.yourstore.com) |
| **Consent Mode V2** | | | |
| Enable Consent | `pixelfly_consent_enabled` | false | Show consent banner |
| Consent Mode | `pixelfly_consent_mode` | 'opt-in' | opt-in (GDPR) or opt-out (CCPA) |
| Consent Region | `pixelfly_consent_region` | 'all' | 'all' or 'gdpr' (EU/EEA/UK only) |
| Banner Position | `pixelfly_consent_position` | 'bottom' | top, bottom, bottom-left, bottom-right |
| Banner Colors | `pixelfly_consent_btn_color`, `pixelfly_consent_bg_color`, `pixelfly_consent_text_color` | Blue/Dark/White | Customizable colors |
| **Delayed Events** | | | |
| Delayed Events | `pixelfly_delayed_enabled` | true | COD handling |
| Delayed Methods | `pixelfly_delayed_payment_methods` | ['cod'] | Payment methods |
| Fire on Status | `pixelfly_delayed_fire_on_status` | ['processing','completed'] | Trigger statuses |
| **Advanced** | | | |
| Debug Mode | `pixelfly_debug_mode` | false | Console logging |
| Event Logging | `pixelfly_event_logging` | false | DB logging |
| Excluded Roles | `pixelfly_excluded_roles` | [] | Skip tracking |

---

## Database Tables

### wp_pixelfly_pending_events
```sql
id              BIGINT UNSIGNED PRIMARY KEY
order_id        BIGINT UNSIGNED (WC order ID)
event_data      LONGTEXT (JSON)
status          VARCHAR(20) ['pending', 'fired', 'failed']
fired_at        DATETIME
created_at      DATETIME
updated_at      DATETIME
```

### wp_pixelfly_event_log (optional, when logging enabled)
```sql
id              BIGINT UNSIGNED PRIMARY KEY
event_type      VARCHAR(50)
event_id        VARCHAR(100)
order_id        BIGINT UNSIGNED
response_code   INT
response_body   TEXT
created_at      DATETIME
```

---

## dataLayer Structure

### Initial Page Load
```javascript
dataLayer.push({
    pagePostType: 'product',
    pagePostType2: 'single-product',
    customerTotalOrders: 5,
    customerTotalOrderValue: 1250.00,
    customerBillingEmail: 'user@example.com',
    customerBillingEmailHash: 'sha256...',
    cartContent: {
        totals: { applied_coupons: [], discount_total: 0, subtotal: 99.00, total: 99.00 },
        items: [{ item_id: '123', item_name: 'Product', price: 99, quantity: 1 }]
    }
});
```

### E-commerce Event
```javascript
dataLayer.push({
    event: 'add_to_cart',
    eventId: 'atc_1234567890_abc123',
    ecommerce: {
        currency: 'USD',
        value: 99.00,
        items: [{
            item_id: '123',
            item_name: 'Product Name',
            price: 99.00,
            quantity: 1,
            item_category: 'Category',
            item_brand: 'Brand',
            sku: 'SKU123'
        }]
    }
});
```

### Purchase Event
```javascript
dataLayer.push({
    event: 'purchase',
    eventId: 'purchase_456_1234567890',
    customerTotalOrders: 1,
    customerBillingEmail: 'customer@example.com',
    customerBillingEmailHash: 'sha256...',
    new_customer: true,
    orderData: {
        attributes: {
            date: '2024-01-15T10:30:00+00:00',
            order_number: 456,
            payment_method: 'stripe',
            status: 'processing'
        },
        totals: {
            currency: 'USD',
            total: 149.00,
            subtotal: 139.00,
            shipping_total: 10.00,
            discount_total: 0
        },
        customer: {
            billing: { first_name: 'John', email: 'customer@example.com', ... },
            shipping: { first_name: 'John', ... }
        }
    },
    ecommerce: {
        currency: 'USD',
        transaction_id: '456',
        value: 149.00,
        tax: 0,
        shipping: 10.00,
        items: [...]
    }
});
```

---

## Key Hooks Used

### WordPress/WooCommerce Actions
- `plugins_loaded` - Plugin initialization
- `wp_head` - GTM head script, dataLayer init
- `wp_body_open` - GTM noscript (body)
- `wp_footer` - Checkout events, blocks compatibility
- `wp_enqueue_scripts` - Load JS file
- `woocommerce_after_single_product` - view_item event
- `woocommerce_after_shop_loop` - view_item_list event
- `woocommerce_after_cart` - view_cart event
- `woocommerce_thankyou` - purchase event
- `woocommerce_checkout_order_processed` - Store delayed event
- `woocommerce_order_status_changed` - Fire delayed event

### jQuery/WooCommerce JS Events
- `added_to_cart` - AJAX add to cart success
- `removed_from_cart` - AJAX remove from cart
- `adding_to_cart` - Before AJAX add to cart
- `found_variation` - Variable product selection
- `updated_cart_totals` - Cart update complete
- `updated_checkout` - Checkout AJAX complete

---

## Summary

**Where data goes:**

1. **ALL browser events** → `window.dataLayer` → **GTM** → GA4, Meta Pixel, etc.
2. **Delayed COD purchases only** → `PixelFly_API` → **PixelFly Server** → Meta CAPI, etc.

The plugin does NOT send all events to PixelFly API. Only COD/delayed purchase events use the server-side API. Everything else is handled by GTM through the browser dataLayer.

---

## Consent Mode V2 (v1.1.0)

Google Consent Mode V2 implementation for GDPR/CCPA compliance with customizable consent banner.

### How It Works

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        CONSENT MODE V2 FLOW                                  │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│   1. Page Load - PixelFly_Consent sets consent defaults BEFORE GTM loads    │
│      gtag('consent', 'default', { analytics_storage: 'denied', ... })       │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│   2. Check if banner should show:                                            │
│      - Consent not already given (no cookie)?                               │
│      - Region setting: 'all' OR visitor in GDPR country?                    │
│      - Not admin page?                                                       │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                   ┌────────────────┴────────────────┐
                   │                                 │
                   ▼                                 ▼
            Show Banner                        Don't Show
                   │                           (Tracking OK)
                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│   3. User Action:                                                            │
│      • Accept All → Grant all consent signals                               │
│      • Reject All → Deny all (except security_storage)                      │
│      • Cookie Settings → Granular control                                   │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│   4. gtag('consent', 'update', { ... })                                     │
│      Save to cookie (365 days) + dataLayer push                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Consent Signals

| Signal | Description | Default (opt-in) |
|--------|-------------|------------------|
| `analytics_storage` | Google Analytics cookies | denied |
| `ad_storage` | Advertising cookies | denied |
| `ad_user_data` | Send user data for ads | denied |
| `ad_personalization` | Personalized advertising | denied |
| `functionality_storage` | Functional cookies | denied |
| `personalization_storage` | Personalization cookies | denied |
| `security_storage` | Security cookies | **granted** (always) |

### Region Targeting

| Setting | Behavior |
|---------|----------|
| `all` | Show consent banner to all visitors worldwide |
| `gdpr` | Show only to visitors from GDPR countries (EU/EEA + UK) |

**GDPR Countries (31):** AT, BE, BG, HR, CY, CZ, DK, EE, FI, FR, DE, GR, HU, IE, IT, LV, LT, LU, MT, NL, PL, PT, RO, SK, SI, ES, SE, IS, LI, NO, GB

Uses WooCommerce geolocation (`WC_Geolocation::geolocate_ip()`) for country detection.

### Class: PixelFly_Consent

```php
// Key methods:
is_consent_enabled()      // Check if consent mode is enabled in settings
should_show_banner()      // Region check + existing cookie check
get_consent_state()       // Parse pixelfly_consent cookie (JSON)
has_consent($type)        // Check specific consent type (analytics/marketing/personalization)
inject_consent_defaults() // Output gtag consent defaults (wp_head, priority 0)
render_consent_banner()   // Output consent banner HTML/CSS/JS (wp_footer)
is_gdpr_country()         // Check if visitor from GDPR region
get_visitor_country()     // Get country via WC_Geolocation::geolocate_ip()
```

### JavaScript API (pixelflyConsent object)

```javascript
// Available after page load
window.pixelflyConsent = {
    acceptAll: function() { ... },     // Grant all consent, save cookie, hide banner
    rejectAll: function() { ... },     // Deny all (except security), save cookie, hide banner
    showSettings: function() { ... },  // Show settings modal (checkboxes pre-checked)
    saveSettings: function() { ... },  // Save selected options, update gtag, hide banner
    hasConsent: function(type) { ... } // Check if user granted specific consent type
};
```

### Cookie Format

```javascript
// Cookie name: pixelfly_consent
// Expiry: 365 days
// Value: JSON encoded object
{
    "analytics": true,        // analytics_storage
    "marketing": true,        // ad_storage, ad_user_data
    "personalization": true,  // ad_personalization, personalization_storage
    "timestamp": 1737561600   // Unix timestamp when consent was given
}
```

---

## Custom Loader (v1.1.0)

Load GTM/GA4/Meta Pixel scripts through a first-party domain to bypass ad blockers and improve tracking accuracy.

### End-to-End Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        CUSTOM LOADER END-TO-END FLOW                         │
└─────────────────────────────────────────────────────────────────────────────┘

Standard GTM (blocked by ad blockers):
  Browser → www.googletagmanager.com/gtm.js → BLOCKED by uBlock/AdGuard ❌

Custom Loader (bypasses ad blockers):

Step 1: WooCommerce Plugin (class-pixelfly-custom-loader.php)
────────────────────────────────────────────────────────────
  Hook: wp_head (priority 1)
  - Outputs obfuscated GTM loader script
  - Maps: id=GTM-XXX → c=XXX, l=dataLayer → d=pfData
  - src="https://t.yourstore.com/pf.js?c=XXX&d=pfData"
                                    │
                                    ▼
Step 2: Cloudflare Worker Routing (src/index.ts)
────────────────────────────────────────────────
  - isProxyRoute('/pf.js') → true
  - handleScriptProxy(request, ctx)
                                    │
                                    ▼
Step 3: Script Proxy (src/proxy/index.ts)
─────────────────────────────────────────
  a. Reverse-map parameters:
     - c=XXX → id=GTM-XXX (restore prefix)
     - d=pfData → l=dataLayer (restore name)

  b. Check edge cache (1-hour TTL):
     - Cache key = TARGET URL (shared across customers)
     - HIT → Skip to URL rewriting
     - MISS → Fetch from origin

  c. Fetch from origin:
     GET https://www.googletagmanager.com/gtm.js?id=GTM-XXX
     Store in edge cache via ctx.waitUntil()

  d. URL Rewriting (rewriteScriptUrls):
     All internal URLs → customer's first-party domain
     - googletagmanager.com → t.yourstore.com
     - google-analytics.com/g/collect → t.yourstore.com/a/c
     - connect.facebook.net → t.yourstore.com
     - facebook.com/tr → t.yourstore.com/s/p
                                    │
                                    ▼
Step 4: Browser Execution
─────────────────────────
  GTM script executes with ALL internal URLs pointing to first-party domain:
  - GA4 collect: t.yourstore.com/a/c ✓ (not blocked!)
  - Meta pixel: t.yourstore.com/s/p ✓ (not blocked!)
                                    │
                                    ▼
Step 5: Collect Endpoint Proxy (handleCollectProxy)
───────────────────────────────────────────────────
  - Pass-through to origin (NO caching)
  - Copy headers: User-Agent, Origin, Referer
  - Forward request body (POST data)
  - Return with CORS headers
```

### Obfuscated Routes

| Route | Target | Purpose | Caching |
|-------|--------|---------|---------|
| `/pf.js` | googletagmanager.com/gtm.js | GTM loader | 1 hour edge |
| `/px.js` | googletagmanager.com/gtag/js | GA4/gtag | 1 hour edge |
| `/sp.js` | connect.facebook.net/fbevents.js | Meta Pixel | 1 hour edge |
| `/a/c` | google-analytics.com/g/collect | GA4 collect | Pass-through |
| `/a/j` | google-analytics.com/j/collect | GA4 join | Pass-through |
| `/s/p` | facebook.com/tr | Meta pixel tracking | Pass-through |

### Obfuscated Parameters

| Original | Obfuscated | Description |
|----------|------------|-------------|
| `id=GTM-XXX` | `c=XXX` | Container ID (GTM- prefix stripped) |
| `l=dataLayer` | `d=pfData` | DataLayer variable name |

### Script Injection Comparison

```javascript
// Standard GTM (blocked by ad blockers):
(function(w,d,s,l,i){
  w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
  var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
  j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
  f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXX');

// Custom Loader (not blocked - different variable names, different domain):
(function(a,b,c,d,e){
  a[d]=a[d]||[];a[d].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
  var f=b.getElementsByTagName(c)[0],g=b.createElement(c),h=d!='pfData'?'&d='+d:'';
  g.async=true;g.src='https://t.yourstore.com/pf.js?c='+e+h;
  f.parentNode.insertBefore(g,f);
})(window,document,'script','pfData','XXX');
```

### Class: PixelFly_Custom_Loader

```php
// Key methods:
is_enabled()                          // Check if custom loader active
inject_custom_loader_script()         // Output obfuscated GTM script (wp_head)
get_noscript_iframe()                 // Noscript fallback via custom domain
validate_domain($domain)              // Validate domain format
test_custom_domain($domain, $gtm_id)  // Test custom domain connectivity
```

### Filter: pixelfly_use_standard_gtm

When Custom Loader is enabled, it registers a filter to prevent duplicate GTM injection:

```php
// Custom Loader registers at wp_head priority 1
add_filter('pixelfly_use_standard_gtm', '__return_false');

// DataLayer checks filter before injecting GTM (wp_head priority 2)
$use_standard_gtm = apply_filters('pixelfly_use_standard_gtm', true);
if (!$use_standard_gtm) {
    return; // Custom Loader already handled GTM injection
}
```

### Cache Strategy

| Component | Cache Duration | Notes |
|-----------|---------------|-------|
| Script proxy (pf.js, px.js, sp.js) | 1 hour | Edge cache, shared across customers |
| Collect endpoints (a/c, a/j, s/p) | None | Pass-through, real-time tracking data |
| Cache key | Target URL | Same GTM-XXX = same cache entry |
| URL rewriting | Per-request | Customer domain specific |

---

## Theme Compatibility (v1.0.0)

The plugin is designed to work with ANY WooCommerce-compatible theme by using WooCommerce hooks rather than theme-specific selectors.

### Tested Themes
- **Storefront** (official WooCommerce theme)
- **Astra** (including Astra Pro with single product AJAX)
- **Hello Elementor**
- **Flatsome**
- **OceanWP**
- **Kadence**

### AJAX Add to Cart Handling

```javascript
// Primary: Listen for WooCommerce's standard event
jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
    // Fire add_to_cart event to dataLayer
});

// Fallback: For custom cart plugins that don't trigger standard events
jQuery(document).ajaxComplete(function(event, xhr, settings) {
    if (url.indexOf('admin-ajax.php') > -1 && window.pixelflySingleProductPending) {
        // Fire add_to_cart event after 300ms delay
    }
});
```

### Product Data Extraction Fallbacks

```javascript
// 1. Try .pixelfly-product-data element inside form
// 2. Try .pixelfly-product-data in product container
// 3. Try .pixelfly-product-data anywhere on page
// 4. Fallback: Extract from DOM elements
function getProductDataFromPage() {
    // - Product ID from form button, hidden input, or body class (postid-XXX)
    // - Product name from .product_title, .entry-title, h1.product-title
    // - Price from .woocommerce-Price-amount
}
```

### Astra Theme Specifics

Astra theme requires special handling for:
- **Sticky add to cart bar:** Supports `.ast-sticky-add-to-cart .single_add_to_cart_button`
- **Single product AJAX:** Detects `astra-woo-single-product-ajax` body class

### Flatsome Theme Specifics

Flatsome theme (v3.17.0+) has its own AJAX add to cart implementation. The plugin handles this with:

```javascript
// Detection: Check for Flatsome-specific indicators
var isFlatsomeTheme = (typeof window.flatsomeVars !== 'undefined') ||
                      (typeof window.Flatsome !== 'undefined') ||
                      document.querySelector('script[src*="flatsome"]') !== null;

// Flatsome triggers wc_fragments_refreshed after cart updates
jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
    // Fire add_to_cart if pending data exists
});

// AJAX response detection for cart updates
jQuery(document).ajaxComplete(function(event, xhr, settings) {
    // Check response for fragments/cart_hash indicating successful add
    var response = JSON.parse(xhr.responseText);
    if (response.fragments || response.cart_hash) {
        // Fire add_to_cart event with shorter delay (150ms)
    }
});
```

**Key Flatsome behaviors handled:**
- AJAX add to cart on single product pages
- Quick view modal add to cart
- `wc_fragments_refreshed` / `wc_fragments_loaded` events for cart updates
- Shorter fallback delay (150ms vs 300ms) for faster response

---

## Event Deduplication

Both browser (FB Pixel via GTM) and server (PixelFly → Meta CAPI) receive the SAME `event_id` for proper deduplication.

### How It Works

```
WooCommerce Plugin                    GTM Web Container
       │                                     │
       │ dataLayer.push({                   │
       │   event: 'add_to_cart',            │
       │   eventId: 'atc_1704897234567_abc' │
       │ })                                 │
       │                                     │
       │                              {{event id Variable}}
       │                                     │
       │                    ┌────────────────┼────────────────┐
       │                    │                │                │
       │                    ▼                ▼                ▼
       │              FB Pixel Tag    PixelFly Tag      GA4 Tags
       │              eventId: ...    eventId: ...
       │                    │                │
       │                    ▼                ▼
       │              Meta Pixel      PixelFly Server
       │                                     │
       │                                     ▼
       │                               Meta CAPI
       │
       └──► Meta deduplicates using matching event_id
```

### GTM Variable Template

The GTM container uses `{{event id Variable}}` (from Community Gallery or custom) that reads `eventId` from dataLayer. This same variable is used by:
- **FB Pixel tags:** Pass as `eventId` parameter
- **PixelFly Server Side tag:** Pass as `eventIdVariable`

This ensures both channels receive identical event IDs.

---

## HPOS Compatibility

The plugin declares compatibility with WooCommerce High-Performance Order Storage (HPOS):

```php
public function declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
}
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| v1.1.0 | Jan 22, 2026 | Added Consent Mode V2 (GDPR/CCPA), Custom Loader (ad blocker bypass), region targeting |
| v1.0.1 | Jan 18, 2026 | Added Flatsome theme support for single product AJAX add to cart |
| v1.0.0 | Jan 10, 2026 | Initial release with theme-agnostic AJAX handling, HPOS compatibility |

### v1.0.1 Fixes
- Added Flatsome theme detection via `flatsomeVars` and `Flatsome` globals
- Added `wc_fragments_refreshed` / `wc_fragments_loaded` event listeners for Flatsome cart updates
- Improved AJAX response detection to check for `fragments` and `cart_hash` in response
- Reduced fallback delay for Flatsome theme (150ms vs 300ms)
- Enhanced form submit handler for Flatsome non-AJAX mode (fires immediately on submit)
- Improved product data extraction with 6 fallback methods for product ID
- Added extensive debug logging for troubleshooting

### v1.0.0 Fixes
- Fixed single product AJAX add to cart for Astra theme
- Added `ajaxComplete` fallback for custom cart plugins
- Added product data DOM extraction fallbacks
- Added `pixelflySingleProductPending` / `pixelflyListProductPending` flags for event deduplication
- Declared HPOS compatibility
