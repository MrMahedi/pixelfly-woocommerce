# PixelFly WooCommerce Plugin - Architecture & Data Flow

**Version:** 1.0.0
**Last Updated:** January 10, 2026

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
- Capture UTM parameters from URL
- Store in session/cookie
- Attach to order meta on checkout

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
| Delayed Events | `pixelfly_delayed_enabled` | true | COD handling |
| Delayed Methods | `pixelfly_delayed_payment_methods` | ['cod'] | Payment methods |
| Fire on Status | `pixelfly_delayed_fire_on_status` | ['processing','completed'] | Trigger statuses |
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
| v1.0.0 | Jan 10, 2026 | Initial release with theme-agnostic AJAX handling, HPOS compatibility |

### v1.0.0 Fixes
- Fixed single product AJAX add to cart for Astra theme
- Added `ajaxComplete` fallback for custom cart plugins
- Added product data DOM extraction fallbacks
- Added `pixelflySingleProductPending` / `pixelflyListProductPending` flags for event deduplication
- Declared HPOS compatibility
