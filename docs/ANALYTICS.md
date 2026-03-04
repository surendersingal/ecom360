# Ecom360 — AI Analytics Platform

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Installation & Setup](#installation--setup)
4. [Modules](#modules)
5. [Analytics Features](#analytics-features)
6. [AI-Powered Insights](#ai-powered-insights)
7. [Event Tracking & SDK](#event-tracking--sdk)
8. [Segments & Audiences](#segments--audiences)
9. [API Reference](#api-reference)
10. [Panel Navigation](#panel-navigation)
11. [Troubleshooting](#troubleshooting)

---

## Overview

Ecom360 is an enterprise-grade, multi-tenant e-commerce analytics platform built with Laravel 12, Filament v3, MongoDB, and Redis. It provides:

- **Real-time traffic monitoring** — Live visitor counts, events/minute, active page breakdown
- **AI-powered insights** — Anomaly detection, predictive revenue forecasting, automated recommendations
- **Advanced segmentation** — Visitor, customer (RFM), and traffic source segments
- **Conversion funnels** — Full e-commerce funnel with drop-off analysis
- **Geographic analytics** — Country-wise visits, city breakdown, device/browser stats
- **Customer journey tracking** — End-to-end session replay and journey mapping
- **JavaScript SDK** — Drop-in tracker for any e-commerce storefront

---

## Architecture

```
┌─────────────────┐     ┌──────────────┐     ┌─────────────┐
│  JS SDK          │────▶│ Ingest API   │────▶│  MongoDB     │
│  (Storefront)    │     │ /api/v1/track│     │  tracking_   │
└─────────────────┘     └──────────────┘     │  events      │
                              │               └─────────────┘
                              │                      │
                         ┌────▼────┐           ┌─────▼──────┐
                         │  Redis   │           │  Services   │
                         │  Cache   │           │  Layer      │
                         │  + Queue │           └─────┬──────┘
                         └─────────┘                  │
                                              ┌───────▼───────┐
                                              │  Filament v3   │
                                              │  Dashboard     │
                                              └───────────────┘
```

**Tech Stack:**
| Component | Technology |
|-----------|-----------|
| Framework | Laravel 12, PHP 8.3 |
| Admin UI | Filament v3 + Livewire |
| Time-series DB | MongoDB 7.x |
| Relational DB | MySQL 8.x |
| Cache/Queue | Redis |
| WebSockets | Laravel Reverb |
| Modules | nwidart/laravel-modules |
| Font | DM Sans |
| Colors | Indigo primary, Slate neutrals |

---

## Installation & Setup

```bash
# Clone and install dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Configure databases in .env
DB_CONNECTION=mysql
DB_DATABASE=ecom360

MONGODB_URI=mongodb://localhost:27017
MONGODB_DATABASE=ecom360

REDIS_HOST=127.0.0.1

# Run migrations
php artisan migrate

# Seed default data
php artisan db:seed

# Build assets
npm run build

# Start the server
php artisan serve --port=8090
```

Access panels:
- **Admin Panel**: `http://127.0.0.1:8090/admin`
- **Tenant Panel**: `http://127.0.0.1:8090/app/{tenant-slug}`

---

## Modules

| Module | Status | Description |
|--------|--------|-------------|
| Analytics | Active | Core analytics engine — tracking, funnels, sessions, RFM, AI insights |
| AiSearch | Active | AI-powered product search (next phase) |
| Marketing | Active | Campaign tracking and attribution |
| Chatbot | Active | Conversational analytics |
| BusinessIntelligence | Active | Advanced BI reports |

---

## Analytics Features

### Real-Time Traffic (`/app/{slug}/real-time-traffic`)
- Active sessions (last 30 minutes)
- Events per minute throughput
- Top pages being viewed right now
- Active countries with live session counts
- Visitor, traffic source, and customer segment breakdowns

### Page Visits (`/app/{slug}/page-visits-analytics`)
- Total page views (30-day window)
- Daily page view trend chart
- Event type distribution (page_view, product_view, add_to_cart, etc.)
- Hourly traffic heatmap
- Top landing pages & exit pages
- Per-page unique visitor counts

### Sessions Explorer (`/app/{slug}/sessions-explorer`)
- Browse individual sessions with event timelines
- Session search by ID
- Event count per session
- User-agent and device info
- Session duration tracking

### Geographic & Device Analytics (`/app/{slug}/geographic-analytics`)
- Visitors by country and city
- Device type breakdown (Desktop, Mobile, Tablet)
- Browser distribution
- Traffic heatmap by hour of day

### Conversion Funnels (`/app/{slug}/funnel-analytics`)
- Full e-commerce funnel: View → Cart → Checkout → Purchase
- Drop-off rates between each step
- Multi-period comparison (7d, 30d, 90d)
- Overall conversion rate tracking

### Product Analytics (`/app/{slug}/product-analytics`)
- Top purchased products by revenue
- Most viewed products
- Cart abandonment by product
- Frequently bought together patterns
- Product performance scoring

### Category Analytics (`/app/{slug}/category-analytics`)
- Category performance (views, carts, purchases, revenue)
- Conversion rates by category
- Daily trend for top categories
- Revenue distribution

### Campaign Analytics (`/app/{slug}/campaign-analytics`)
- UTM parameter tracking and reporting
- Channel attribution (Direct, Organic, Paid, Social)
- Campaign performance comparison
- Referrer source analysis

### Cohort Analysis (`/app/{slug}/cohort-analysis`)
- 6-month retention cohorts
- Repeat purchase rate tracking
- Customer Lifetime Value (CLV) by segment

### Customer Journey (`/app/{slug}/customer-journey`)
- End-to-end session journey mapping
- Event timeline per customer
- Touchpoint analysis

---

## AI-Powered Insights

### Anomaly Detection
The platform uses **z-score based anomaly detection** on:
- **Daily traffic** — Flags spikes/drops exceeding 2 standard deviations from the 30-day moving average
- **Conversion rates** — Detects unusual changes in purchase conversion rates

Each anomaly includes:
- Severity level (warning / critical)
- Current vs. average metric values
- Z-score deviation
- Human-readable explanation

### Revenue Forecasting
**Linear regression model** predicts next 7 days of revenue:
- Uses 30 days of historical purchase data
- Calculates R² confidence score
- Trends: growing, stable, or declining
- Visual chart with historical bars + forecast (dashed)

### Automated Insights Engine
Analyzes data to generate **actionable recommendations**:

| Insight | Trigger | Impact |
|---------|---------|--------|
| Low Add-to-Cart Rate | < 5% of product views | High |
| Cart Abandonment | < 30% cart → checkout rate | Critical |
| Checkout Drop-off | < 50% checkout → purchase rate | Critical |
| Peak Revenue Hours | Top 3 revenue-generating hours | Medium |
| Mobile Conversion Gap | Mobile conversion < 1% | High |
| Top Revenue Driver | Identifies best-selling product | Medium |
| Low Session Depth | Avg depth < 2.5 events | Medium |

---

## Segments & Audiences

### Visitor Segments
| Segment | Definition |
|---------|-----------|
| New Visitors | ≤ 3 events in last 7 days |
| Engaged Visitors | ≥ 5 events in last 30 days |
| Bounced Visitors | Exactly 1 event in last 30 days |

### Customer Segments (RFM-based)
| Segment | RFM Score Range |
|---------|----------------|
| VIP | 13–15 |
| Loyal | 10–12 |
| At Risk | 7–9 |
| Hibernating | 4–6 |
| Churned | 0–3 |

### Traffic Source Segments
| Segment | Detection |
|---------|-----------|
| Direct | No UTM or referrer |
| Organic Search | Referrer contains google, bing, etc. |
| Paid | UTM medium = cpc/ppc/paid |
| Social | Referrer contains facebook, twitter, etc. |
| Mobile | User-agent indicates mobile device |

---

## Event Tracking & SDK

### JavaScript SDK

Include the tracker on your storefront:

```html
<script src="https://your-domain.com/js/ecom360-tracker.js"></script>
<script>
  Ecom360.init({
    tenantId: 'your-tenant-id',
    apiUrl: 'https://your-domain.com/api/v1',
  });
</script>
```

The SDK automatically tracks:
- Page views (on load and SPA navigation)
- Product views
- Add to cart / Remove from cart
- Begin checkout
- Purchases
- Search queries
- Custom events

### Manual Event Tracking

```javascript
// Track a custom event
Ecom360.track('wishlist_add', {
  product_id: 'SKU-123',
  product_name: 'Blue Widget',
  price: 29.99,
});

// Track a purchase
Ecom360.track('purchase', {
  order_id: 'ORD-456',
  order_total: 149.97,
  items: [
    { product_id: 'SKU-123', quantity: 2, price: 29.99 },
    { product_id: 'SKU-789', quantity: 1, price: 89.99 },
  ],
});
```

---

## API Reference

### Ingest Endpoint

```
POST /api/v1/track
Authorization: Bearer {api-token}
Content-Type: application/json

{
  "event_type": "page_view",
  "url": "/products/blue-widget",
  "session_id": "sess_abc123",
  "visitor_id": "vis_xyz789",
  "metadata": {
    "product_id": "SKU-123",
    "product_name": "Blue Widget",
    "referrer": "https://google.com",
    "utm_source": "google",
    "utm_medium": "cpc"
  }
}
```

**Response:**
```json
{
  "status": "ok",
  "event_id": "6507a1b2c3d4e5f6a7b8c9d0"
}
```

### Supported Event Types
| Event Type | Description |
|-----------|-------------|
| `page_view` | Page visit |
| `product_view` | Product detail page view |
| `add_to_cart` | Item added to cart |
| `remove_from_cart` | Item removed from cart |
| `begin_checkout` | Checkout started |
| `purchase` | Order completed |
| `search` | Search query |
| `click` | Custom click event |
| `campaign_event` | Marketing campaign interaction |
| `chat_event` | Chatbot interaction |

### GeoIP Enrichment
All events are automatically enriched with:
- Country, city, region
- Latitude/longitude
- Timezone, ISP
- Device type, browser, OS

Uses [ip-api.com](http://ip-api.com) with **7-day Redis cache** for performance.

---

## Panel Navigation

### Tenant Panel (`/app/{slug}`)

| Group | Pages |
|-------|-------|
| **Overview** | Dashboard |
| **Traffic** | Real-Time, Page Visits, Sessions Explorer, Geo & Devices |
| **Revenue** | Funnel Analytics, Product Analytics, Category Analytics, Campaign Analytics |
| **Audience** | Cohort Analysis, Customer Journey, Customer Profiles, Audience Segments |
| **AI Insights** | AI-Powered Insights |
| **Events** | Custom Events, Custom Event Definitions |
| **Settings** | Webhooks, Behavioral Rules, Storefront Simulator |

### Admin Panel (`/admin`)

| Group | Pages |
|-------|-------|
| **Platform** | Dashboard, Tenants |
| **Analytics** | Events Overview |
| **Settings** | Users, Roles & Permissions |

---

## Troubleshooting

### Common Issues

**MongoDB connection fails:**
```bash
# Check MongoDB is running
mongosh --eval "db.runCommand({ping: 1})"

# Verify .env config
MONGODB_URI=mongodb://localhost:27017
MONGODB_DATABASE=ecom360
```

**Redis connection fails:**
```bash
redis-cli ping  # Should return PONG
```

**No data appearing:**
1. Check that events are being tracked (verify via Storefront Simulator)
2. Ensure the tenant ID matches between the SDK and the panel
3. Check `storage/logs/laravel.log` for errors

**Dark mode contrast issues:**
The platform uses a custom design system with proper contrast ratios. If styles look wrong:
```bash
php artisan view:clear
php artisan cache:clear
npm run build
```

**WebSocket not connecting:**
```bash
php artisan reverb:start  # Start the Reverb WebSocket server
```
