=== Ecom360 Analytics for WooCommerce ===
Contributors: ecom360
Tags: analytics, woocommerce, tracking, ecommerce, events
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Ecom360 Analytics and capture every customer interaction — page views, product views, cart events, checkouts, purchases, logins, registrations, and more.

== Description ==

Ecom360 Analytics is a lightweight, privacy-aware tracking plugin that sends real-time event data from your WooCommerce store to your Ecom360 analytics dashboard.

**What it tracks:**

* **Page views** — every page, with page type classification (shop, product, category, cart, checkout)
* **Product views** — product ID, name, price, category, SKU
* **Add to cart / remove from cart** — including product details, quantity, cart totals
* **Checkout events** — step-by-step (email, address, payment method, place order)
* **Purchases** — full order details: items, totals, coupons, payment method, shipping, tax
* **Order lifecycle** — status changes, completions, refunds
* **Site search** — search queries captured automatically
* **User login & registration** — with email identification for cross-session tracking
* **Product reviews** — product, rating, approval status
* **Wishlist** — compatible with YITH and TI Wishlist plugins
* **Coupon usage** — coupon codes applied at cart/checkout
* **Scroll depth** — 25%, 50%, 75%, 100% milestones
* **Engagement time** — active time on page, excluding hidden/background tabs

**Features:**

* Zero-config tracking — enable and go
* Toggle each event type individually
* Batch event sending to reduce HTTP requests
* Session management with configurable timeout
* UTM parameter capture for campaign attribution
* Lightweight device fingerprinting (non-PII)
* Referrer capture for traffic source analysis
* Works with AJAX-driven WooCommerce themes
* Server-side event capture for reliable order tracking
* Fire-and-forget HTTP calls (no performance impact)
* Connection test from the admin panel
* sendBeacon API for reliable unload tracking
* Admin user exclusion option

== Installation ==

1. Upload the `ecom360-analytics` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **WooCommerce → Ecom360 Analytics** (or Settings → Ecom360 Analytics)
4. Enter your Ecom360 API Endpoint and API Key
5. Click **Test Connection** to verify
6. Configure which events to track
7. Save settings — tracking starts immediately

== Frequently Asked Questions ==

= Do I need WooCommerce? =

The plugin works best with WooCommerce but will track basic page views and sessions on any WordPress site.

= Does this slow down my store? =

No. Server-side events use non-blocking (fire-and-forget) HTTP calls. Client-side events use the Fetch API with keepalive and sendBeacon on page unload.

= What about GDPR? =

You control which tracking features to enable. Device fingerprinting and UTM capture can be independently toggled off. The plugin does not set any third-party cookies or load external scripts.

= How does abandoned cart detection work? =

The plugin saves a cart snapshot whenever the cart is updated. If a logged-in customer leaves without completing purchase, the snapshot is available for your Ecom360 dashboard to process.

== Changelog ==

= 1.0.0 =
* Initial release
* Full WooCommerce event tracking
* Admin settings panel with connection testing
* Client-side JS SDK with batching support
* Server-side PHP hooks for reliable order capture
