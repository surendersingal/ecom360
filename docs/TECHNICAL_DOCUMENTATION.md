# Ecom360 — Complete Technical Documentation

> **Version:** 1.0.0  
> **Last Updated:** July 2025  
> **Platform:** Multi-Tenant SaaS E-commerce Analytics & Marketing Platform

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Technology Stack](#2-technology-stack)
3. [Project Structure](#3-project-structure)
4. [Module Reference](#4-module-reference)
   - 4.1 [Analytics Module](#41-analytics-module)
   - 4.2 [DataSync Module](#42-datasync-module)
   - 4.3 [Marketing Module](#43-marketing-module)
   - 4.4 [Chatbot Module](#44-chatbot-module)
   - 4.5 [AI Search Module](#45-ai-search-module)
   - 4.6 [Business Intelligence Module](#46-business-intelligence-module)
5. [Core Application Layer](#5-core-application-layer)
6. [Authentication & Authorization](#6-authentication--authorization)
7. [Database Architecture](#7-database-architecture)
8. [API Reference (150+ Endpoints)](#8-api-reference-150-endpoints)
9. [Event Bus & Inter-Module Communication](#9-event-bus--inter-module-communication)
10. [WordPress Plugin](#10-wordpress-plugin)
11. [Magento 2 Plugin](#11-magento-2-plugin)
12. [Testing](#12-testing)
13. [How to Edit Existing Code](#13-how-to-edit-existing-code)
14. [How to Update the Codebase](#14-how-to-update-the-codebase)
15. [How to Develop New Features](#15-how-to-develop-new-features)
16. [Environment Setup](#16-environment-setup)
17. [Deployment Guide](#17-deployment-guide)
18. [Coding Standards & Conventions](#18-coding-standards--conventions)

---

## 1. Architecture Overview

Ecom360 is a **multi-tenant SaaS platform** that provides e-commerce analytics, marketing automation, AI-powered search, chatbot support, and business intelligence to online stores running on WooCommerce (WordPress) and Magento 2.

### High-Level Architecture

```
┌───────────────────────────────────────────────────────────────────┐
│                      Ecom360 SaaS Platform                        │
│                  Laravel 11 · PHP 8.3 · Sanctum Auth              │
├───────────────────────────────────────────────────────────────────┤
│   Core Layer: Tenant · User · RBAC · WidgetRegistry · Settings    │
│   Event Bus: IntegrationEvent → EventBusRouter (Redis Queue)      │
├──────────┬──────────┬──────────┬──────────┬──────────┬───────────┤
│ Analytics│ DataSync │Marketing │ Chatbot  │ AiSearch │    BI     │
│ 32 svcs  │  3 svcs  │ 10 svcs  │  5 svcs  │  5 svcs  │ 12 svcs  │
│  6 ctrls │  1 ctrl  │  7 ctrls │  1 ctrl  │  1 ctrl  │  7 ctrls │
├──────────┴──────────┴──────────┴──────────┴──────────┴───────────┤
│   Storage: MySQL (relational) + MongoDB (high-velocity NoSQL)     │
│   Queue: Redis · Auth: Sanctum + API Keys · RBAC: Spatie          │
├───────────────────────────────────────────────────────────────────┤
│   Platform Plugins                                                │
│   ├── WordPress/WooCommerce Plugin (tracker + DataSync + widgets) │
│   └── Magento 2 Module (18 observers + cron sync + widgets)       │
└───────────────────────────────────────────────────────────────────┘
```

### Design Principles

| Principle | Implementation |
|---|---|
| **Multi-Tenancy** | Every query scoped to `tenant_id`; API key isolation per tenant |
| **Modular Architecture** | 6 independent modules via `nwidart/laravel-modules` |
| **Thin Controllers** | All business logic in Service classes; controllers only dispatch |
| **Event-Driven** | `IntegrationEvent` + `EventBusRouter` for async inter-module messaging |
| **Dual Database** | MySQL for relational data, MongoDB for high-velocity analytics events |
| **Strict Typing** | `declare(strict_types=1)` in every file; `final class` pattern |
| **RBAC** | Spatie permissions with per-tenant role provisioning |

---

## 2. Technology Stack

### Backend

| Component | Technology | Version |
|---|---|---|
| **Framework** | Laravel | 11.x |
| **Language** | PHP | 8.3+ |
| **Relational DB** | MySQL (AWS RDS) | 8.0+ |
| **Document DB** | MongoDB | 8.0+ |
| **Cache / Queue** | Redis (Predis) | 3.4+ |
| **Auth** | Laravel Sanctum | 4.0 |
| **RBAC** | Spatie Laravel Permission | 6.24 |
| **Modules** | nwidart/laravel-modules | 11.0 |
| **WebSockets** | Laravel Reverb | 1.7 |
| **MongoDB ODM** | mongodb/laravel-mongodb | 5.6 |

### Frontend / Admin

| Component | Technology |
|---|---|
| **Admin Panel** | Filament (Admin + Tenant panels) |
| **CSS** | Tailwind CSS |
| **Build** | Vite |

### Platform Plugins

| Plugin | Platform | Version |
|---|---|---|
| **ecom360-analytics** | WordPress/WooCommerce | 1.0.0 (WP 5.8+, WC 6.0+, PHP 7.4+) |
| **Ecom360_Analytics** | Magento 2 | 1.0.0 |

### Testing

| Tool | Purpose |
|---|---|
| PHPUnit 11 | Unit, Feature, Integration tests |
| Custom PHP E2E scripts | End-to-end API validation |
| k6 (JavaScript) | Load testing |

---

## 3. Project Structure

```
ecom360/
├── app/                          # Core application code
│   ├── Console/Commands/         # Artisan commands
│   ├── Contracts/                # Interfaces (WidgetInterface)
│   ├── Events/                   # IntegrationEvent
│   ├── Filament/                 # Admin & Tenant panel resources
│   │   ├── Admin/                # Super-admin panel
│   │   └── Tenant/               # Per-tenant panel
│   ├── Http/
│   │   ├── Controllers/          # Core controllers (DashboardController)
│   │   ├── Middleware/            # ResolveTenant, EnsureSuperAdmin
│   │   └── Requests/             # Form request validation
│   ├── Listeners/                # EventBusRouter
│   ├── Models/                   # Tenant, User, TenantSetting, DashboardLayout
│   ├── Providers/                # AppServiceProvider, Filament providers
│   ├── Services/                 # WidgetRegistry, SettingsRegistry, RoleManagerService
│   └── Traits/                   # ApiResponse trait
│
├── Modules/                      # Feature modules (nwidart)
│   ├── Analytics/                # 32 services, 6 controllers, 6 models
│   ├── DataSync/                 # 3 services, 1 controller, 11 models
│   ├── Marketing/                # 10 services, 7 controllers
│   ├── Chatbot/                  # 5 services, 1 controller
│   ├── AiSearch/                 # 5 services, 1 controller
│   └── BusinessIntelligence/     # 12 services, 7 controllers
│
├── config/                       # Laravel & module configuration
├── database/
│   ├── migrations/               # 12 migration files
│   ├── factories/                # Model factories
│   └── seeders/                  # Database seeders
│
├── routes/
│   ├── api.php                   # Core API routes
│   ├── web.php                   # Web routes
│   ├── channels.php              # Broadcast channels
│   └── console.php               # Console routes
│
├── tests/
│   ├── Feature/                  # Feature tests (GDPR, edge cases)
│   ├── Integration/              # 15 phase integration tests
│   ├── Unit/                     # Unit tests
│   ├── comprehensive_e2e_test.php    # 186 endpoint E2E test
│   ├── datasync_e2e_validate.php     # 101 DataSync E2E tests
│   ├── magento_datasync_e2e.php      # 157 Magento E2E tests
│   └── load/                     # k6 load test scripts
│
├── wordpress-plugin/             # WordPress/WooCommerce plugin
│   └── ecom360-analytics/
│
├── magento-plugin/               # Magento 2 module
│   └── Jetrails/Ecom360/
│
├── stubs/nwidart-stubs/          # Module scaffolding templates
├── storage/                      # Logs, cache, sessions
├── vendor/                       # Composer dependencies
└── public/                       # Web root (index.php)
```

### Module Internal Structure

Each module follows a consistent structure under `Modules/{ModuleName}/`:

```
Modules/{ModuleName}/
├── app/
│   ├── Http/
│   │   ├── Controllers/          # API controllers
│   │   ├── Middleware/            # Module-specific middleware
│   │   └── Requests/             # FormRequest validation classes
│   ├── Jobs/                     # Queued jobs
│   ├── Listeners/                # Event listeners
│   ├── Models/                   # Eloquent/MongoDB models
│   ├── Providers/
│   │   ├── {Module}ServiceProvider.php  # Main provider
│   │   ├── EventServiceProvider.php
│   │   └── RouteServiceProvider.php
│   └── Services/                 # Business logic services
├── config/                       # Module configuration
├── database/
│   ├── migrations/               # Module-specific migrations
│   ├── factories/
│   └── seeders/
├── resources/
│   └── views/
├── routes/
│   ├── api.php                   # Module API routes
│   └── web.php                   # Module web routes
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
├── module.json                   # Module metadata & activation
├── package.json
└── vite.config.js
```

---

## 4. Module Reference

### 4.1 Analytics Module

**Path:** `Modules/Analytics/`  
**Purpose:** Core analytics engine — tracks user behavior, resolves identities, computes attribution, and provides real-time & historical analytics dashboards.

#### Services (32 files)

| Service | Description |
|---|---|
| `TrackingService` | Core event ingestion — validates, resolves fingerprints/identities, computes attribution, persists to MongoDB |
| `IdentityResolutionService` | Links anonymous sessions to known customer identities |
| `FingerprintResolutionService` | Device fingerprint matching for cross-session tracking |
| `AttributionService` | Marketing attribution modeling (first-touch, last-touch, multi-touch) |
| `LiveContextService` | Real-time context enrichment (device, geo, referrer) |
| `GeoIpService` | IP-to-geography resolution |
| `SessionAnalyticsService` | Session duration, engagement, bounce rate analytics |
| `EcommerceFunnelService` | Conversion funnel analysis (browse → cart → checkout → purchase) |
| `IntentScoringService` | Real-time purchase intent scoring |
| `PredictiveCLVService` | Customer lifetime value prediction with what-if scenarios |
| `CohortAnalysisService` | Customer cohort retention analysis |
| `RevenueAnalyticsService` | Revenue metrics, trends, and breakdowns |
| `RevenueWaterfallService` | Period-over-period revenue waterfall visualization |
| `GeographicAnalyticsService` | Geographic and device distribution analytics |
| `ProductAnalyticsService` | Product performance, trending, cross-sell analytics |
| `CampaignAnalyticsService` | UTM & campaign performance analytics |
| `CustomerJourneyService` | Full customer journey mapping with touchpoints |
| `SegmentEvaluationService` | Dynamic audience segment evaluation |
| `AudienceBuilderService` | Visual audience segment builder with rule engine |
| `AudienceSyncService` | Audience export to external destinations (Meta, Google) |
| `CompetitiveBenchmarkService` | Industry competitive benchmarking |
| `CompetitorPriceService` | Competitor price monitoring |
| `SmartRecommendationService` | AI-powered product recommendations |
| `WeatherService` | Weather-based analytics correlation |
| `NaturalLanguageQueryService` | Natural language → analytics query translation |
| `WhyExplanationService` | AI "Why" explanation engine for metric changes |
| `BehavioralTriggerService` | Real-time behavioral trigger evaluation (popups, interventions) |
| `RealTimeAlertsService` | Configurable real-time alert system |
| `PrivacyComplianceService` | GDPR/CCPA compliance utilities |
| `AiAnalyticsService` | AI-powered analytics and anomaly detection |
| `AdvancedAnalyticsOpsService` | Operational analytics (system health, throughput) |
| `CdpAdvancedService` | Customer Data Platform — advanced identity & profile merging |

#### Controllers (6 files)

| Controller | Purpose |
|---|---|
| `PublicIngestionController` | Public SDK endpoints (`/api/v1/collect`) — API key auth |
| `IngestionController` | Authenticated event ingestion (`/api/v1/analytics/ingest`) |
| `AnalyticsController` | Legacy CRUD resource controller |
| `AnalyticsApiController` | Enterprise analytics API (overview, traffic, revenue, etc.) |
| `AdvancedAnalyticsController` | Advanced analytics (CLV, journey, NLQ, triggers, etc.) |
| `AnalyticsReportController` | Report generation endpoint |

#### Models (6 models)

| Model | Storage | Description |
|---|---|---|
| `TrackingEvent` | MongoDB (`tracking_events`) | High-velocity event data (page_view, add_to_cart, purchase, etc.) |
| `CustomerProfile` | MongoDB (`customer_profiles`) | Identity-resolved customer profiles with RFM scores, fingerprints |
| `AudienceSegment` | MySQL | Dynamic audience segment definitions with JSON rules |
| `BehavioralRule` | MySQL | Real-time intervention rules (popup, discount) with conditions |
| `CustomEventDefinition` | MySQL | Tenant-defined custom event schemas |
| `TenantWebhook` | MySQL | Per-tenant webhook endpoints with HMAC signing |

#### Dashboard Widgets

Registered in `AnalyticsServiceProvider`:
- `RevenueChartWidget` — Revenue trend chart
- `TrafficOverviewWidget` — Traffic KPI overview
- `RfmDistributionWidget` — RFM customer distribution
- `FunnelWidget` — Conversion funnel visualization

---

### 4.2 DataSync Module

**Path:** `Modules/DataSync/`  
**Purpose:** Bi-directional data synchronization between e-commerce platforms (WooCommerce, Magento) and Ecom360. Handles product catalogs, orders, customers, inventory, and more.

#### Services (3 + 5 Normalizers)

| Service | Description |
|---|---|
| `DataSyncService` | Core orchestrator — register connections, validate permissions, normalize, upsert, log |
| `PermissionService` | Per-entity consent management (ConsentLevel enum: full, anonymized, aggregated, none) |
| `Normalizers/ProductNormalizer` | Transforms platform-specific product data to canonical schema |
| `Normalizers/CategoryNormalizer` | Category hierarchy normalization |
| `Normalizers/OrderNormalizer` | Order + line items normalization |
| `Normalizers/CustomerNormalizer` | Customer PII normalization (consent-aware) |
| `Normalizers/InventoryNormalizer` | Stock/inventory normalization |

#### Controller (1 file)

| Controller | Purpose |
|---|---|
| `SyncController` | All sync endpoints — register, heartbeat, permissions, data sync |

#### Middleware

| Middleware | Purpose |
|---|---|
| `ValidateSyncAuth` | Server-to-server authentication via `X-Ecom360-Key` + `X-Ecom360-Secret` headers |

#### Models (11 models)

| Model | Storage | Description |
|---|---|---|
| `SyncConnection` | MySQL | Connected store with platform metadata |
| `SyncLog` | MySQL | Audit log per sync batch |
| `SyncPermission` | MySQL | Per-entity consent with ConsentLevel |
| `SyncedProduct` | MongoDB | Product catalog with variants |
| `SyncedCategory` | MongoDB | Category hierarchy |
| `SyncedOrder` | MongoDB | Orders with line items |
| `SyncedCustomer` | MongoDB | Customer data (consent-required) |
| `SyncedInventory` | MongoDB | Stock/inventory data |
| `SyncedSalesData` | MongoDB | Aggregated daily sales |
| `SyncedAbandonedCart` | MongoDB | Abandoned carts (consent-required) |
| `SyncedPopupCapture` | MongoDB | Lead captures from popups |

#### Data Flow

```
WooCommerce/Magento Plugin
        │
        ▼ (HTTP POST with X-Ecom360-Key/Secret)
  ValidateSyncAuth Middleware
        │
        ▼
  SyncController
        │
        ▼
  DataSyncService
   ├── Resolve SyncConnection
   ├── Check PermissionService (consent level)
   ├── Normalize via Normalizer (platform → canonical)
   ├── Upsert into MongoDB collection
   ├── Log SyncBatch to MySQL
   └── Dispatch IntegrationEvent (for other modules)
```

---

### 4.3 Marketing Module

**Path:** `Modules/Marketing/`  
**Purpose:** Multi-channel marketing automation — email, SMS, WhatsApp, RCS, push notifications with visual flow builder, template engine, and rules engine.

#### Services (10 files)

| Service | Description |
|---|---|
| `ContactService` | Contact CRUD, bulk import, unsubscribe management |
| `CampaignService` | Campaign lifecycle — create, schedule, send, duplicate, stats |
| `TemplateService` | Email/SMS template CRUD with variable support |
| `FlowExecutionService` | Visual automation flow execution engine |
| `CouponService` | Dynamic coupon generation and tracking |
| `RulesEngineService` | Marketing rule evaluation engine |
| `AdvancedMarketingService` | Advanced marketing features |
| `HyperPersonalizationService` | AI hyper-personalization engine |
| `MagicLinkService` | Passwordless magic link authentication |
| `VariableResolverService` | Template variable resolution (customer name, order details, etc.) |

#### Controllers (7 files)

| Controller | Purpose |
|---|---|
| `MarketingController` | Filament panel controller |
| `Api/CampaignController` | Campaign CRUD + send/stats/duplicate |
| `Api/ChannelController` | Channel CRUD + test/providers |
| `Api/ContactController` | Contact CRUD + bulk-import/unsubscribe |
| `Api/FlowController` | Flow CRUD + canvas/activate/pause/enroll/stats |
| `Api/TemplateController` | Template CRUD + preview/duplicate |
| `Api/WebhookController` | External provider webhook handler |

#### Channel Providers

Registered in `MarketingServiceProvider`:
- `EmailProvider`
- `WhatsAppProvider`
- `RcsProvider`
- `PushProvider`
- `SmsProvider`

---

### 4.4 Chatbot Module

**Path:** `Modules/Chatbot/`  
**Purpose:** AI-powered customer support chatbot with intent detection, order tracking, rage-click detection, and proactive support.

#### Services (5 files)

| Service | Description |
|---|---|
| `ChatService` | Core chat message handling and response generation |
| `AdvancedChatService` | Advanced AI conversation capabilities |
| `IntentService` | Customer intent detection and classification |
| `OrderTrackingService` | Real-time order status tracking via chat |
| `ProactiveSupportService` | Proactive support triggers (abandoned cart, rage clicks) |

#### Controller (1 file)

| Controller | Purpose |
|---|---|
| `ChatbotController` | Chat send, history, conversations, resolve, widget-config, analytics, rage-click |

#### API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/v1/chatbot/send` | POST | Send message to chatbot |
| `/api/v1/chatbot/rage-click` | POST | Report rage click event |
| `/api/v1/chatbot/history/{conversationId}` | GET | Get conversation history |
| `/api/v1/chatbot/conversations` | GET | List all conversations |
| `/api/v1/chatbot/resolve/{conversationId}` | POST | Mark conversation resolved |
| `/api/v1/chatbot/widget-config` | GET | Get widget configuration |
| `/api/v1/chatbot/analytics` | GET | Chatbot performance analytics |

---

### 4.5 AI Search Module

**Path:** `Modules/AiSearch/`  
**Purpose:** AI-powered product search with semantic understanding, visual search, personalization, and trending analysis.

#### Services (5 files)

| Service | Description |
|---|---|
| `SearchService` | Core search query execution |
| `SemanticSearchService` | NLP-based semantic search understanding |
| `PersonalizedSearchService` | User-behavior-aware personalized rankings |
| `RelevanceService` | Search relevance scoring and tuning |
| `VisualSearchService` | Image-based visual product search |

#### Controller (1 file)

| Controller | Purpose |
|---|---|
| `AiSearchController` | Search, suggest, visual search, similar products, trending, analytics |

#### API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/v1/search` | POST | Execute search query |
| `/api/v1/search/suggest` | GET | Autocomplete suggestions |
| `/api/v1/search/visual` | POST | Visual/image search |
| `/api/v1/search/similar/{productId}` | GET | Find similar products |
| `/api/v1/search/trending` | GET | Trending search terms |
| `/api/v1/search/analytics` | GET | Search analytics |

---

### 4.6 Business Intelligence Module

**Path:** `Modules/BusinessIntelligence/`  
**Purpose:** Advanced BI & reporting — custom reports, KPI dashboards, predictive analytics, alerting, and data export.

#### Services (12 files)

| Service | Description |
|---|---|
| `ReportService` | Report CRUD and execution engine |
| `KpiService` | KPI definition, computation, and refresh |
| `AlertService` | Threshold-based alert creation and evaluation |
| `ExportService` | Data export (CSV, Excel, PDF) |
| `PredictionService` | ML-based predictive analytics |
| `QueryBuilderService` | Dynamic query construction from visual builder |
| `AdvancedBIService` | Advanced BI features |
| `AutonomousOpsService` | Self-optimizing operational insights |
| `BenchmarkService` | Industry benchmarking |
| `DynamicPricingService` | AI-powered dynamic pricing recommendations |
| `InventoryService` | Inventory forecasting and optimization |
| `ReturnRiskService` | Return probability prediction |

#### Controllers (7 files)

| Controller | Purpose |
|---|---|
| `BusinessIntelligenceController` | Filament panel controller |
| `Api/ReportController` | Report CRUD + execute/templates/from-template |
| `Api/DashboardController` | Dashboard CRUD + duplicate |
| `Api/KpiController` | KPI CRUD + refresh/defaults |
| `Api/AlertController` | Alert CRUD + history/acknowledge/evaluate |
| `Api/ExportController` | Export CRUD + download |
| `Api/InsightsController` | Predictions, benchmarks, query builder, fields |

---

## 5. Core Application Layer

### Models

| Model | Location | Storage | Description |
|---|---|---|---|
| `Tenant` | `app/Models/Tenant.php` | MySQL | Multi-tenant root — name, slug, domain, api_key, secret_key, is_active |
| `User` | `app/Models/User.php` | MySQL | User accounts with tenant_id, Spatie roles/permissions, Sanctum tokens |
| `TenantSetting` | `app/Models/TenantSetting.php` | MySQL | Per-tenant key-value settings |
| `DashboardLayout` | `app/Models/DashboardLayout.php` | MySQL | Saved dashboard widget layouts |

### Core Services

| Service | Location | Description |
|---|---|---|
| `WidgetRegistry` | `app/Services/WidgetRegistry.php` | Central dashboard widget registry — validates `WidgetInterface`, stores widget metadata |
| `SettingsRegistry` | `app/Services/SettingsRegistry.php` | Per-module, per-tenant settings with Redis cache (1-hour TTL) |
| `RoleManagerService` | `app/Services/RoleManagerService.php` | Provisions default RBAC roles & permissions per tenant |

### Event System

| Component | Location | Description |
|---|---|---|
| `IntegrationEvent` | `app/Events/IntegrationEvent.php` | `final class` implementing `ShouldDispatchAfterCommit` — carries `moduleName`, `eventName`, `payload` |
| `EventBusRouter` | `app/Listeners/EventBusRouter.php` | Runs on Redis `event-bus` queue — routes events between modules via `match` statement |

### Middleware

| Middleware | Location | Description |
|---|---|---|
| `ResolveTenant` | `app/Http/Middleware/ResolveTenant.php` | Resolves tenant from route slug, verifies active status, checks user membership |
| `EnsureSuperAdmin` | `app/Http/Middleware/EnsureSuperAdmin.php` | Guards super-admin-only routes |

### Helpers

| Helper | Location | Description |
|---|---|---|
| `safe_num()` | `app/helpers.php` | Safely formats numeric values for KPI cards |

---

## 6. Authentication & Authorization

### Authentication Methods

Ecom360 uses three distinct authentication strategies:

#### 1. Sanctum Token Auth (Dashboard & Authenticated APIs)

Used for all authenticated API endpoints. Users receive tokens via login.

```
Authorization: Bearer {sanctum_token}
```

**Protected routes:** All `/api/v1/analytics/`, `/api/v1/bi/`, `/api/v1/marketing/`, `/api/v1/chatbot/`, `/api/v1/search/` endpoints (except public collect).

#### 2. API Key Auth (Public SDK / Tracking)

Used by JavaScript tracker on storefronts for event collection.

**Middleware:** `ValidateTrackingApiKey`  
**Header:** `X-Api-Key: {tenant_api_key}`

**Protected routes:**
- `POST /api/v1/collect` — Single event
- `POST /api/v1/collect/batch` — Batch events

#### 3. Server-to-Server Auth (DataSync)

Used by WordPress/Magento plugins for data synchronization.

**Middleware:** `ValidateSyncAuth`  
**Headers:**
```
X-Ecom360-Key: {tenant_api_key}
X-Ecom360-Secret: {tenant_secret_key}
```

**Protected routes:** All `/api/v1/sync/*` endpoints.

### Authorization (RBAC)

Powered by **Spatie Laravel Permission**:

- Permissions are organized by module: `analytics.*`, `ai_search.*`, `chatbot.*`, `business_intelligence.*`, `marketing.*`
- `RoleManagerService` provisions default roles per tenant on creation
- `is_super_admin` flag on User model for platform-wide access

---

## 7. Database Architecture

### MySQL (Relational Data)

Used for structured relational data requiring ACID transactions.

**Core tables:**
- `tenants` — Multi-tenant root
- `users` — User accounts (linked to tenants)
- `tenant_settings` — Per-tenant key-value configuration
- `dashboard_layouts` — Saved dashboard layouts
- `permissions`, `roles`, `model_has_roles`, `model_has_permissions` — Spatie RBAC
- `personal_access_tokens` — Sanctum tokens
- `notifications` — User notifications

**Analytics module tables (MySQL):**
- `audience_segments` — Segment definitions
- `behavioral_rules` — Trigger rules
- `custom_event_definitions` — Custom event schemas
- `tenant_webhooks` — Webhook endpoints

**DataSync module tables (MySQL):**
- `sync_connections` — Connected store registrations
- `sync_logs` — Sync audit logs
- `sync_permissions` — Per-entity consent levels

### MongoDB (High-Velocity NoSQL)

Used for high-volume event data and denormalized documents.

**Database:** `ecom360`

**Analytics collections:**
- `tracking_events` — All tracked events (page views, clicks, purchases, etc.)
- `customer_profiles` — Identity-resolved customer profiles

**DataSync collections:**
- `synced_products` — Product catalog
- `synced_categories` — Category hierarchy
- `synced_orders` — Order data
- `synced_customers` — Customer data
- `synced_inventory` — Stock data
- `synced_sales_data` — Aggregated sales
- `synced_abandoned_carts` — Abandoned carts
- `synced_popup_captures` — Lead captures

### Connection Configuration

```php
// config/database.php
'mongodb' => [
    'driver'   => 'mongodb',
    'dsn'      => env('MONGODB_URI', 'mongodb://localhost:27017'),
    'database' => env('MONGODB_DATABASE', 'ecom360'),
],
```

All MongoDB models extend `MongoDB\Laravel\Eloquent\Model` from the `mongodb/laravel-mongodb` package.

---

## 8. API Reference (150+ Endpoints)

### Public Endpoints (No Auth / API Key Auth)

#### Event Collection (API Key via `X-Api-Key`)

| Method | Endpoint | Description | Rate Limit |
|---|---|---|---|
| POST | `/api/v1/collect` | Collect single tracking event | 300/min |
| POST | `/api/v1/collect/batch` | Collect batch events (max 50) | 60/min |
| OPTIONS | `/api/v1/collect` | CORS preflight | — |
| OPTIONS | `/api/v1/collect/batch` | CORS preflight | — |

#### Marketing Webhooks

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/marketing/webhooks/{provider}` | Provider webhook handler |

### Authenticated Endpoints (Sanctum Bearer Token)

#### Analytics — Core (`/api/v1/analytics/`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/overview` | Dashboard KPI overview |
| GET | `/traffic` | Traffic & event statistics |
| GET | `/realtime` | Real-time active metrics |
| GET | `/revenue` | Revenue analytics |
| GET | `/products` | Product performance |
| GET | `/categories` | Category analytics |
| GET | `/sessions` | Session engagement |
| GET | `/page-visits` | Page-level analytics |
| GET | `/funnel` | Conversion funnel |
| GET | `/customers` | Customer & RFM analytics |
| GET | `/cohorts` | Cohort retention |
| GET | `/campaigns` | Campaign/UTM analytics |
| GET | `/geographic` | Geographic & device distribution |
| GET | `/export` | Raw event data export |
| POST | `/events/custom` | Track custom event |
| GET | `/events/custom/definitions` | List custom event definitions |
| POST | `/events/custom/definitions` | Create custom event definition |
| POST | `/ingest` | Authenticated event ingestion (120/min) |
| GET | `/report` | Generate report |

#### Analytics — Advanced (`/api/v1/analytics/advanced/`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/clv` | Predictive CLV |
| POST | `/clv/what-if` | CLV what-if simulation |
| GET | `/revenue-waterfall` | Revenue waterfall |
| POST | `/why` | Why explanation engine |
| POST | `/triggers/evaluate` | Evaluate behavioral triggers |
| GET | `/journey` | Customer journey map |
| GET | `/journey/drop-offs` | Journey drop-off points |
| GET | `/recommendations` | Smart recommendations |
| GET | `/audience/segments` | Audience segments |
| POST | `/audience/sync` | Sync audience to destination |
| GET | `/audience/destinations` | Available sync destinations |
| GET | `/pulse` | Real-time pulse |
| GET | `/alerts` | Real-time alerts |
| POST | `/alerts/{alert}/acknowledge` | Acknowledge alert |
| GET | `/ask` | Natural language query |
| GET | `/ask/suggest` | NLQ suggestions |
| GET | `/benchmarks` | Competitive benchmarks |

#### Analytics — Legacy CRUD

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/analytics` | List analytics |
| POST | `/api/v1/analytics` | Create analytic |
| GET | `/api/v1/analytics/{analytic}` | Show analytic |
| PUT/PATCH | `/api/v1/analytics/{analytic}` | Update analytic |
| DELETE | `/api/v1/analytics/{analytic}` | Delete analytic |

#### DataSync (`/api/v1/sync/`)

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/register` | Sync Auth | Register/update store connection |
| POST | `/heartbeat` | Sync Auth | Connection heartbeat |
| POST | `/permissions` | Sync Auth | Update sync permissions |
| POST | `/products` | Sync Auth | Sync product catalog |
| POST | `/categories` | Sync Auth | Sync categories |
| POST | `/inventory` | Sync Auth | Sync inventory |
| POST | `/sales` | Sync Auth | Sync sales data |
| POST | `/orders` | Sync Auth | Sync orders (consent required) |
| POST | `/customers` | Sync Auth | Sync customers (consent required) |
| POST | `/abandoned-carts` | Sync Auth | Sync abandoned carts (consent required) |
| POST | `/popup-captures` | Sync Auth | Sync popup captures (consent required) |
| GET | `/status` | Sync Auth | Connection status |

#### Marketing (`/api/v1/marketing/`)

**Contacts:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/contacts` | List contacts |
| POST | `/contacts` | Create contact |
| GET | `/contacts/{contact}` | Show contact |
| PUT/PATCH | `/contacts/{contact}` | Update contact |
| DELETE | `/contacts/{contact}` | Delete contact |
| POST | `/contacts/bulk-import` | Bulk import contacts |
| POST | `/contacts/{contact}/unsubscribe` | Unsubscribe contact |

**Lists:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/lists` | List contact lists |
| POST | `/lists` | Create list |
| POST | `/lists/{list}/members` | Add members to list |
| DELETE | `/lists/{list}/members` | Remove members from list |

**Templates:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/templates` | List templates |
| POST | `/templates` | Create template |
| GET | `/templates/{template}` | Show template |
| PUT/PATCH | `/templates/{template}` | Update template |
| DELETE | `/templates/{template}` | Delete template |
| GET | `/templates/{template}/preview` | Preview rendered template |
| POST | `/templates/{template}/duplicate` | Duplicate template |

**Campaigns:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/campaigns` | List campaigns |
| POST | `/campaigns` | Create campaign |
| GET | `/campaigns/{campaign}` | Show campaign |
| PUT/PATCH | `/campaigns/{campaign}` | Update campaign |
| DELETE | `/campaigns/{campaign}` | Delete campaign |
| POST | `/campaigns/{campaign}/send` | Send campaign |
| GET | `/campaigns/{campaign}/stats` | Campaign statistics |
| POST | `/campaigns/{campaign}/duplicate` | Duplicate campaign |

**Flows (Automation):**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/flows` | List automation flows |
| POST | `/flows` | Create flow |
| GET | `/flows/{flow}` | Show flow |
| PUT/PATCH | `/flows/{flow}` | Update flow |
| DELETE | `/flows/{flow}` | Delete flow |
| PUT | `/flows/{flow}/canvas` | Save flow canvas (visual editor) |
| POST | `/flows/{flow}/activate` | Activate flow |
| POST | `/flows/{flow}/pause` | Pause flow |
| POST | `/flows/{flow}/enroll` | Enroll contact in flow |
| GET | `/flows/{flow}/stats` | Flow execution statistics |

**Channels:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/channels` | List channels |
| POST | `/channels` | Create channel |
| GET | `/channels/{channel}` | Show channel |
| PUT/PATCH | `/channels/{channel}` | Update channel |
| DELETE | `/channels/{channel}` | Delete channel |
| POST | `/channels/{channel}/test` | Test channel delivery |
| GET | `/channels/providers/{type}` | List available providers |

#### Chatbot (`/api/v1/chatbot/`)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/send` | Send message |
| POST | `/rage-click` | Report rage click |
| GET | `/history/{conversationId}` | Conversation history |
| GET | `/conversations` | List conversations |
| POST | `/resolve/{conversationId}` | Resolve conversation |
| GET | `/widget-config` | Widget configuration |
| GET | `/analytics` | Chatbot analytics |

#### AI Search (`/api/v1/search/`)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/` | Execute search |
| GET | `/suggest` | Autocomplete suggestions |
| POST | `/visual` | Visual/image search |
| GET | `/similar/{productId}` | Similar products |
| GET | `/trending` | Trending searches |
| GET | `/analytics` | Search analytics |

#### Business Intelligence (`/api/v1/bi/`)

**Reports:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/reports` | List reports |
| POST | `/reports` | Create report |
| GET | `/reports/{report}` | Show report |
| PUT/PATCH | `/reports/{report}` | Update report |
| DELETE | `/reports/{report}` | Delete report |
| POST | `/reports/{report}/execute` | Execute report |
| GET | `/reports/meta/templates` | List report templates |
| POST | `/reports/from-template` | Create from template |

**Dashboards:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/dashboards` | List dashboards |
| POST | `/dashboards` | Create dashboard |
| GET | `/dashboards/{dashboard}` | Show dashboard |
| PUT/PATCH | `/dashboards/{dashboard}` | Update dashboard |
| DELETE | `/dashboards/{dashboard}` | Delete dashboard |
| POST | `/dashboards/{dashboard}/duplicate` | Duplicate dashboard |

**KPIs:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/kpis` | List KPIs |
| POST | `/kpis` | Create KPI |
| GET | `/kpis/{kpi}` | Show KPI |
| PUT/PATCH | `/kpis/{kpi}` | Update KPI |
| DELETE | `/kpis/{kpi}` | Delete KPI |
| POST | `/kpis/refresh` | Refresh all KPIs |
| POST | `/kpis/defaults` | Initialize default KPIs |

**Alerts:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/alerts` | List alerts |
| POST | `/alerts` | Create alert |
| GET | `/alerts/{alert}` | Show alert |
| PUT/PATCH | `/alerts/{alert}` | Update alert |
| DELETE | `/alerts/{alert}` | Delete alert |
| GET | `/alerts/{alert}/history` | Alert history |
| POST | `/alerts/history/{alertHistory}/acknowledge` | Acknowledge alert |
| POST | `/alerts/evaluate` | Evaluate all alerts |

**Exports:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/exports` | List exports |
| POST | `/exports` | Create export |
| GET | `/exports/{export}` | Show export |
| DELETE | `/exports/{export}` | Delete export |
| GET | `/exports/{export}/download` | Download export file |

**Insights:**

| Method | Endpoint | Description |
|---|---|---|
| GET | `/insights/predictions` | List predictions |
| POST | `/insights/predictions/generate` | Generate prediction |
| GET | `/insights/benchmarks` | Industry benchmarks |
| POST | `/insights/query` | Custom query builder |
| GET | `/insights/fields/{source}` | Available query fields |

---

## 9. Event Bus & Inter-Module Communication

### Overview

Modules communicate asynchronously through the **Event Bus** — a Redis-backed queue system using Laravel's event/listener pattern.

### How It Works

```
Module A                          Module B
   │                                 │
   ├── dispatch(IntegrationEvent)    │
   │        │                        │
   │        ▼                        │
   │   EventBusRouter                │
   │   (Redis 'event-bus' queue)     │
   │        │                        │
   │        ├── match event name ────▶ dispatch(Job)
   │        │                        │
   │        └── log unhandled        │
```

### IntegrationEvent

```php
// app/Events/IntegrationEvent.php
final class IntegrationEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $moduleName,
        public readonly string $eventName,
        public readonly array  $payload,
    ) {}
}
```

### Dispatching an Event

```php
// From any module service:
IntegrationEvent::dispatch('Analytics', 'report.generated', [
    'tenant_id' => $tenantId,
    'report_id' => $reportId,
    'data'      => $reportData,
]);
```

### Event Routing Table

| Source Event | Target | Action |
|---|---|---|
| `Analytics::report.generated` | Marketing | `HandleAnalyticsReport` job |
| `Analytics::rfm_segment_changed` | Marketing | Process segment change |
| `Analytics::audience_segment_entered` | Marketing | Auto-enroll in flow |
| `Analytics::audience_segment_exited` | Marketing | Handle exit |
| `AiSearch::search.completed` | Analytics | `RecordSearchEvent` job |
| `Chatbot::intent.captured` | BI | `AggregateIntent` job |
| `analytics::behavioral_trigger` | Marketing | Trigger intervention |
| `BI::alert_triggered` | — | Log alert |
| Various status events | — | Logged for audit |

### Adding a New Event Route

1. Dispatch from source module:
   ```php
   IntegrationEvent::dispatch('YourModule', 'event_name', $payload);
   ```

2. Add handler in `app/Listeners/EventBusRouter.php`:
   ```php
   'YourModule::event_name' => TargetModule\Jobs\HandleEvent::dispatch($event->payload),
   ```

---

## 10. WordPress Plugin

### Overview

**Path:** `wordpress-plugin/ecom360-analytics/`  
**Plugin Name:** Ecom360 Analytics  
**Version:** 1.0.0  
**Requirements:** WordPress 5.8+, PHP 7.4+, WooCommerce 6.0+

### File Structure

```
ecom360-analytics/
├── ecom360-analytics.php              # Main plugin file (bootstrap, hooks)
├── uninstall.php                      # Clean uninstall handler
├── readme.txt                         # WordPress.org readme
├── includes/
│   ├── class-ecom360-tracker.php      # Frontend JS tracker injection
│   ├── class-ecom360-woocommerce.php  # WooCommerce event hooks
│   ├── class-ecom360-datasync.php     # DataSync cron for bulk sync
│   ├── class-ecom360-admin.php        # Admin settings page
│   ├── class-ecom360-settings.php     # Settings management
│   ├── class-ecom360-rest.php         # WordPress REST API endpoints
│   ├── class-ecom360-popup.php        # Popup/lead capture widget
│   ├── class-ecom360-chatbot.php      # Chatbot widget renderer
│   ├── class-ecom360-aisearch.php     # AI search widget
│   ├── class-ecom360-push.php         # Push notification integration
│   ├── class-ecom360-database.php     # Custom DB tables
│   ├── class-ecom360-event-queue.php  # Event batching queue
│   └── class-ecom360-abandoned-cart.php # Abandoned cart detection
├── assets/
│   ├── js/ecom360-tracker.js          # Client-side tracker (v2)
│   └── css/ecom360-popup.css          # Popup styles
└── admin/
    ├── js/                            # Admin JavaScript
    └── css/                           # Admin styles
```

### Features (24 features)

| # | Feature | Class | Description |
|---|---|---|---|
| 1 | Page View Tracking | Tracker | Automatic page view events on every page |
| 2 | Session Tracking | Tracker | Session ID generation & tracking |
| 3 | WooCommerce Integration | WooCommerce | Product view, add-to-cart, checkout, purchase hooks |
| 4 | Event Queue | EventQueue | Batch events client-side before sending |
| 5 | DataSync — Products | DataSync | Bulk product catalog sync via cron |
| 6 | DataSync — Categories | DataSync | Category hierarchy sync |
| 7 | DataSync — Orders | DataSync | Order data sync |
| 8 | DataSync — Customers | DataSync | Customer data sync (consent-aware) |
| 9 | DataSync — Inventory | DataSync | Stock level sync |
| 10 | DataSync — Sales | DataSync | Aggregated sales data sync |
| 11 | DataSync — Abandoned Carts | AbandonedCart | Cart abandonment detection & sync |
| 12 | Popup Captures | Popup | Lead capture popups (exit intent, timed) |
| 13 | Chatbot Widget | Chatbot | Embedded AI chatbot |
| 14 | AI Search Widget | AiSearch | AI-powered search overlay |
| 15 | Push Notifications | Push | Web push notification integration |
| 16 | Admin Settings | Admin/Settings | Configuration UI in WordPress admin |
| 17 | REST API | Rest | Local REST endpoints for AJAX operations |
| 18 | Custom DB Tables | Database | Local queue/cart tables |
| 19 | Store Registration | DataSync | Auto-register store with Ecom360 |
| 20 | Heartbeat | DataSync | Periodic heartbeat to Ecom360 |
| 21 | Permission Sync | DataSync | Consent level management |
| 22 | Search Tracking | WooCommerce | WooCommerce search event tracking |
| 23 | Review Tracking | WooCommerce | Product review submission tracking |
| 24 | Wishlist Tracking | WooCommerce | Wishlist add/remove tracking |

### Configuration

Navigate to **WooCommerce → Ecom360 Analytics** in WordPress admin:

| Setting | Description |
|---|---|
| API URL | Ecom360 server base URL |
| API Key | Tenant API key |
| Secret Key | Tenant secret key |
| Enable Tracking | Toggle event tracking |
| Enable DataSync | Toggle data synchronization |
| Enable Chatbot | Toggle chatbot widget |
| Enable AI Search | Toggle AI search widget |
| Enable Popups | Toggle popup captures |
| Enable Push | Toggle push notifications |

---

## 11. Magento 2 Plugin

### Overview

**Path:** `magento-plugin/Jetrails/Ecom360/`  
**Module:** `Ecom360_Analytics`  
**Version:** 1.0.0

### File Structure

```
Ecom360/Analytics/
├── registration.php              # Module registration
├── composer.json                 # Composer metadata
├── etc/
│   ├── module.xml               # Module declaration
│   ├── config.xml               # Default configuration
│   ├── di.xml                   # Dependency injection
│   ├── events.xml               # Observer registration
│   ├── acl.xml                  # Access control list
│   ├── crontab.xml              # Cron job scheduling
│   ├── db_schema.xml            # Database schema
│   ├── adminhtml/               # Admin-specific config
│   │   ├── system.xml           # System configuration UI
│   │   ├── menu.xml             # Admin menu
│   │   └── routes.xml           # Admin routes
│   └── frontend/
│       ├── events.xml           # Frontend observers
│       └── routes.xml           # Frontend routes
├── Api/                          # Service contracts
├── Block/
│   ├── Tracker.php              # Frontend JS tracker block
│   ├── AiSearchWidget.php       # AI search widget
│   ├── ChatbotWidget.php        # Chatbot widget
│   ├── PopupWidget.php          # Popup widget
│   └── PushNotification.php     # Push notification block
├── Console/Command/              # CLI commands
│   ├── SyncAll.php, SyncProducts.php, SyncOrders.php
│   ├── SyncCustomers.php, SyncCategories.php
│   ├── TestConnection.php
│   └── ProcessAbandonedCarts.php
├── Controller/
│   ├── Popup/Submit.php         # Popup submission handler
│   ├── Cart/Recover.php         # Cart recovery handler
│   └── Adminhtml/               # Admin controllers
├── Cron/                         # 10 cron jobs
│   ├── ProcessEventQueueCron.php
│   ├── SyncProductsCron.php, SyncOrdersCron.php
│   ├── SyncCustomersCron.php, SyncCategoriesCron.php
│   ├── SyncInventoryCron.php, SyncSalesCron.php
│   ├── SyncPopupCapturesCron.php
│   ├── AbandonedCartCron.php
│   └── FetchInterventionsCron.php
├── Helper/
│   ├── ApiClient.php            # HTTP client for Ecom360 API
│   └── Config.php               # System configuration reader
├── Model/
│   ├── DataSync.php             # Core sync logic
│   ├── EventQueue.php           # Event queue management
│   ├── AbandonedCart.php        # Cart tracking
│   └── ResourceModel/           # DB resource models
├── Observer/ (18 observers)
│   ├── AddToCartObserver.php    # catalog_product_add_to_cart_after
│   ├── RemoveFromCartObserver.php
│   ├── CartSaveObserver.php
│   ├── OrderPlacedObserver.php  # checkout_onepage_controller_success_action
│   ├── OrderStatusObserver.php
│   ├── OrderRefundObserver.php
│   ├── CheckoutSuccessObserver.php
│   ├── ShipmentObserver.php
│   ├── RefundObserver.php
│   ├── CustomerRegisterObserver.php
│   ├── CustomerLoginObserver.php
│   ├── CustomerSaveObserver.php
│   ├── ProductSaveObserver.php
│   ├── CategorySaveObserver.php
│   ├── InventoryObserver.php
│   ├── SearchObserver.php
│   ├── ReviewSaveObserver.php
│   └── WishlistAddObserver.php
├── Setup/                        # Install/upgrade scripts
├── view/
│   ├── adminhtml/templates/
│   └── frontend/
│       ├── layout/default.xml
│       ├── templates/            # tracker, popup, chatbot, aisearch, push
│       └── web/css/
```

### Features (24 features — parity with WordPress)

All 24 features from the WordPress plugin are replicated in Magento via observers, cron jobs, blocks, and templates.

### Configuration

Navigate to **Stores → Configuration → Ecom360 → Analytics** in Magento admin.

---

## 12. Testing

### Test Suite Overview

| Suite | File | Tests | Type |
|---|---|---|---|
| **Laravel Tests** | `tests/` (PHPUnit) | 295 tests (1,280 assertions) | Unit + Feature + Integration |
| **Comprehensive E2E** | `tests/comprehensive_e2e_test.php` | 186 tests | All 150+ API endpoints |
| **DataSync E2E** | `tests/datasync_e2e_validate.php` | 101 tests | DataSync + WordPress flows |
| **Magento E2E** | `tests/magento_datasync_e2e.php` | 157 tests | Magento plugin + DataSync |
| **Total** | — | **739 tests** | — |

### Integration Test Phases (15 phases)

| Phase | File | Coverage |
|---|---|---|
| Phase 1 | `Phase1_CdpIdentityResolutionTest.php` | CDP identity resolution — fingerprint → session → customer linking |
| Phase 2 | `Phase2_AiSearchTest.php` | AI search — semantic, visual, personalized, trending |
| Phase 3 | `Phase3_RealTimeAnalyticsTest.php` | Real-time analytics — pulse, alerts, streaming |
| Phase 4 | `Phase4_ChatbotTest.php` | Chatbot — intent detection, conversation, rage clicks |
| Phase 5 | `Phase5_MarketingTest.php` | Marketing — contacts, campaigns, flows, channels |
| Phase 6 | `Phase6_BiOperationsTest.php` | BI — reports, KPIs, dashboards, exports |
| Phase 7 | `Phase7_AdminCoreTest.php` | Admin — tenant management, RBAC, settings |
| Phase 8 | `Phase8_SecurityDefenseTest.php` | Security — injection, XSS, CSRF, auth bypass |
| Phase 9 | `Phase9_InfraResilienceTest.php` | Infrastructure — rate limits, timeouts, failover |
| Phase 10 | `Phase10_CdpEdgeCasesTest.php` | CDP edge cases — merge conflicts, duplicates |
| Phase 11 | `Phase11_CatalogSearchTest.php` | Catalog search — facets, filters, pagination |
| Phase 12 | `Phase12_MlLlmGuardrailsTest.php` | ML/LLM — guardrails, prompt injection, hallucination |
| Phase 13 | `Phase13_FinancialReconciliationTest.php` | Financial — revenue reconciliation, currency |
| Phase 14 | `Phase14_CatastrophicEdgeCasesTest.php` | Catastrophic — data corruption recovery, cascade failures |
| Phase 15 | `Phase15_DataSyncTest.php` | DataSync — full sync pipeline validation |

### Running Tests

```bash
# Run all Laravel tests (Unit + Feature + Integration)
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration

# Run specific test file
php artisan test tests/Integration/Phase1_CdpIdentityResolutionTest.php

# Run specific test method
php artisan test --filter=test_can_resolve_identity

# Run E2E tests (requires running server at port 8090)
php tests/comprehensive_e2e_test.php
php tests/datasync_e2e_validate.php
php tests/magento_datasync_e2e.php

# Run load tests (k6)
k6 run tests/load/ecom360-full-load-test.js
```

### Writing New Tests

**Integration test pattern:**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;

final class MyNewFeatureTest extends TestCase
{
    private Tenant $tenant;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_my_feature_returns_expected_data(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->getJson('/api/v1/analytics/overview');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['revenue', 'orders']]);
    }
}
```

---

## 13. How to Edit Existing Code

### Editing a Service

All business logic resides in **Service classes**. Controllers should remain thin.

**Example: Adding a new method to `TrackingService`**

1. Open `Modules/Analytics/app/Services/TrackingService.php`
2. Add your method:

```php
public function getEventsByType(int|string $tenantId, string $eventType, int $limit = 100): array
{
    return TrackingEvent::where('tenant_id', $tenantId)
        ->where('event_type', $eventType)
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get()
        ->toArray();
}
```

3. Expose via controller — edit the appropriate controller (e.g., `AnalyticsApiController.php`)
4. Add a route in `Modules/Analytics/routes/api.php`
5. Write a test in `Modules/Analytics/tests/` or `tests/Integration/`

### Editing a Controller

Controllers live at `Modules/{Module}/app/Http/Controllers/`.

**Key rules:**
- Keep controllers thin — max 5-10 lines per method
- Use dependency injection (constructor or method injection)
- Return using `ApiResponse` trait or Laravel API Resources
- Use FormRequest classes for validation (never inline)

**Example:**

```php
public function getByType(Request $request): JsonResponse
{
    $validated = $request->validate(['type' => 'required|string']);

    $events = $this->trackingService->getEventsByType(
        $request->user()->tenant_id,
        $validated['type']
    );

    return response()->json(['data' => $events]);
}
```

### Editing Routes

Each module has its own route file at `Modules/{Module}/routes/api.php`.

**Route patterns:**

```php
// Public route (no auth)
Route::post('/endpoint', [Controller::class, 'method']);

// Sanctum-protected route
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/protected', [Controller::class, 'method']);
});

// API Resource routes (CRUD)
Route::apiResource('resources', ResourceController::class);
```

### Editing Models

**MySQL models** extend `Illuminate\Database\Eloquent\Model`:
```php
// app/Models/Tenant.php or Modules/{Module}/app/Models/
final class MyModel extends Model {
    protected $fillable = ['field1', 'field2'];
}
```

**MongoDB models** extend `MongoDB\Laravel\Eloquent\Model`:
```php
// Modules/{Module}/app/Models/
final class MyMongoModel extends \MongoDB\Laravel\Eloquent\Model {
    protected $connection = 'mongodb';
    protected $collection = 'my_collection';
    protected $fillable = ['field1', 'field2'];
}
```

### Editing Middleware

- **Core middleware:** `app/Http/Middleware/`
- **Module middleware:** `Modules/{Module}/app/Http/Middleware/`

Register in the module's `RouteServiceProvider` or in `bootstrap/app.php`.

---

## 14. How to Update the Codebase

### Adding a Database Migration

```bash
# Core migration
php artisan make:migration create_my_table

# Module migration
php artisan module:make-migration create_my_table Analytics
```

**Migration pattern:**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('my_table', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('my_table');
    }
};
```

```bash
# Run migrations
php artisan migrate
```

### Adding a MongoDB Collection

MongoDB collections are created implicitly. Just create a model:

```php
final class MyDocument extends \MongoDB\Laravel\Eloquent\Model
{
    protected $connection = 'mongodb';
    protected $collection = 'my_documents';
    protected $fillable = ['tenant_id', 'data', 'metadata'];
}
```

For indexes, create an Artisan command:

```php
// Console command
\DB::connection('mongodb')
    ->collection('my_documents')
    ->createIndex(['tenant_id' => 1, 'created_at' => -1]);
```

### Updating Dependencies

```bash
# Update all PHP dependencies
composer update

# Update specific package
composer update mongodb/laravel-mongodb

# Update npm dependencies
npm update

# Rebuild frontend assets
npm run build
```

### Adding New API Endpoints

1. **Create FormRequest** (if needed):
   ```bash
   php artisan module:make-request MyRequest Analytics
   ```

2. **Add route** in `Modules/{Module}/routes/api.php`:
   ```php
   Route::get('/my-endpoint', [MyController::class, 'myMethod']);
   ```

3. **Add controller method** — keep thin, delegate to service

4. **Add service method** — all business logic here

5. **Write tests** — both unit and integration

6. **Verify:**
   ```bash
   php artisan route:list --path=api/v1/my-endpoint
   php artisan test
   ```

### Modifying the Event Bus

To add a new inter-module event route:

1. **Dispatch from source:**
   ```php
   IntegrationEvent::dispatch('ModuleName', 'event.name', $payload);
   ```

2. **Create handler job** in target module:
   ```php
   // Modules/TargetModule/app/Jobs/HandleMyEvent.php
   final class HandleMyEvent implements ShouldQueue
   {
       use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

       public function __construct(public readonly array $payload) {}

       public function handle(): void
       {
           // Process event
       }
   }
   ```

3. **Register route** in `app/Listeners/EventBusRouter.php`:
   ```php
   'ModuleName::event.name' => HandleMyEvent::dispatch($event->payload),
   ```

---

## 15. How to Develop New Features

### Creating a New Module

```bash
# Scaffold a new module
php artisan module:make MyModule

# This creates:
# Modules/MyModule/
#   ├── app/Http/Controllers/
#   ├── app/Models/
#   ├── app/Providers/MyModuleServiceProvider.php
#   ├── config/
#   ├── database/migrations/
#   ├── routes/api.php
#   ├── routes/web.php
#   ├── module.json
#   └── ...
```

**Enable the module:**

```bash
php artisan module:enable MyModule
```

This updates `modules_statuses.json`.

### Module Development Checklist

- [ ] Create module: `php artisan module:make MyModule`
- [ ] Define models (MySQL and/or MongoDB)
- [ ] Create database migrations
- [ ] Implement service classes (business logic)
- [ ] Create controllers (thin, delegate to services)
- [ ] Define routes in `routes/api.php`
- [ ] Add FormRequest validation classes
- [ ] Register services in `MyModuleServiceProvider`
- [ ] Register dashboard widgets (if applicable)
- [ ] Add event bus integration (dispatch/handle IntegrationEvents)
- [ ] Write unit tests
- [ ] Write integration tests
- [ ] Update E2E test scripts
- [ ] Document API endpoints

### Creating a New Service

```bash
php artisan module:make-service MyService Analytics
```

**Service pattern:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Modules\Analytics\Models\TrackingEvent;

final class MyService
{
    public function __construct(
        private readonly TrackingEvent $trackingEvent,
    ) {}

    public function computeMetric(int|string $tenantId, array $options = []): array
    {
        $query = $this->trackingEvent
            ->where('tenant_id', $tenantId);

        if (isset($options['date_from'])) {
            $query->where('created_at', '>=', $options['date_from']);
        }

        // Business logic...

        return [
            'metric_value' => $result,
            'computed_at'  => now()->toISOString(),
        ];
    }
}
```

**Register in ServiceProvider:**

```php
// Modules/Analytics/app/Providers/AnalyticsServiceProvider.php
public function register(): void
{
    $this->app->singleton(MyService::class);
}
```

### Creating a New Controller

```bash
php artisan module:make-controller Api/MyController Analytics
```

**Controller pattern:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analytics\Services\MyService;

final class MyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MyService $myService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->myService->computeMetric(
            $request->user()->tenant_id,
            $request->only(['date_from', 'date_to']),
        );

        return $this->success($data);
    }
}
```

### Creating a New Model

```bash
# MySQL model
php artisan module:make-model MyModel Analytics

# With migration
php artisan module:make-model MyModel Analytics -m
```

**MySQL model:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Tenant;

final class MyModel extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'config', 'is_active',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

**MongoDB model:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use MongoDB\Laravel\Eloquent\Model;

final class MyDocument extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'my_documents';

    protected $fillable = [
        'tenant_id', 'event_type', 'payload', 'metadata',
    ];

    protected $casts = [
        'payload'  => 'array',
        'metadata' => 'array',
    ];
}
```

### Creating a New Middleware

```bash
php artisan module:make-middleware MyMiddleware Analytics
```

**Pattern:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pre-processing logic...

        $response = $next($request);

        // Post-processing logic...

        return $response;
    }
}
```

**Register in routes:**

```php
Route::middleware(MyMiddleware::class)->group(function () {
    // Routes...
});
```

### Creating a Dashboard Widget

1. **Implement `WidgetInterface`:**

```php
<?php

declare(strict_types=1);

namespace Modules\Analytics\Widgets;

use App\Contracts\WidgetInterface;

final class MyWidget implements WidgetInterface
{
    public function getIdentifier(): string
    {
        return 'my-custom-widget';
    }

    public function getName(): string
    {
        return 'My Custom Widget';
    }

    public function getModule(): string
    {
        return 'analytics';
    }

    public function getData(int|string $tenantId, array $options = []): array
    {
        // Fetch and return widget data
        return ['value' => 42, 'trend' => '+5%'];
    }
}
```

2. **Register in ServiceProvider:**

```php
$widgetRegistry = $this->app->make(WidgetRegistry::class);
$widgetRegistry->register(new MyWidget());
```

### Adding a New Platform Plugin Feature

When adding a feature that needs plugin support:

1. **Add backend endpoint** (route + controller + service)
2. **WordPress:** Add class in `wordpress-plugin/ecom360-analytics/includes/class-ecom360-{feature}.php`
3. **Magento:** Add observer/cron/block in `magento-plugin/Jetrails/Ecom360/Analytics/`
4. **Maintain feature parity** — both plugins must support the same features
5. **Update E2E tests** — add test cases in all three E2E scripts

---

## 16. Environment Setup

### Prerequisites

| Requirement | Minimum Version |
|---|---|
| PHP | 8.3 |
| Composer | 2.x |
| Node.js | 18+ |
| MongoDB | 7.0+ |
| MySQL | 8.0+ |
| Redis | 7.0+ |

### Installation

```bash
# Clone the repository
git clone <repository-url> ecom360
cd ecom360

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### Environment Configuration

Edit `.env` file:

```dotenv
APP_NAME=Ecom360
APP_ENV=local
APP_URL=http://127.0.0.1:8090

# MySQL Database
DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=ecom360
DB_USERNAME=your-username
DB_PASSWORD=your-password

# MongoDB
MONGODB_URI=mongodb://localhost:27017
MONGODB_DATABASE=ecom360

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# Sanctum
SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
```

### Running the Application

```bash
# Start MongoDB (if local)
mongod --dbpath ~/data/db &

# Run migrations
php artisan migrate

# Start the development server
php artisan serve --port=8090

# Start queue worker (in separate terminal)
php artisan queue:work redis --queue=event-bus,default

# Build frontend assets
npm run dev       # Development with HMR
npm run build     # Production build
```

**Or use the `dev` composer script:**

```bash
composer dev
# Runs: server + queue + pail (log viewer) + vite concurrently
```

### Module Management

```bash
# List all modules and their status
php artisan module:list

# Enable a module
php artisan module:enable Analytics

# Disable a module
php artisan module:disable Analytics

# Module status file
cat modules_statuses.json
```

---

## 17. Deployment Guide

### Pre-Deployment Checklist

```bash
# 1. Run all tests
php artisan test
php tests/comprehensive_e2e_test.php

# 2. Check for errors
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 3. Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 4. Run migrations
php artisan migrate --force

# 5. Build frontend
npm run build
```

### Server Requirements

| Component | Recommended |
|---|---|
| PHP | 8.3 with extensions: mbstring, openssl, pdo, mongodb, redis |
| Web Server | Nginx or Apache |
| MySQL | 8.0+ (or AWS RDS) |
| MongoDB | 8.0+ (or Atlas) |
| Redis | 7.0+ (or ElastiCache) |
| Queue | Supervisor for `php artisan queue:work` |

### Queue Workers

Configure Supervisor for persistent queue workers:

```ini
[program:ecom360-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/ecom360/artisan queue:work redis --queue=event-bus,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/ecom360/storage/logs/worker.log
```

---

## 18. Coding Standards & Conventions

### PHP Coding Standards

| Rule | Enforcement |
|---|---|
| `declare(strict_types=1)` | Required in every PHP file |
| `final class` | Default for all classes |
| PSR-12 | Enforced via Laravel Pint |
| Read-only properties | Used where applicable |
| PHP 8.3 features | Typed properties, enums, match expressions |
| No inline controllers | All logic in Service classes |
| FormRequest validation | Never use `$request->validate()` inline |
| API Resources | Use for standardized JSON output |

### Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Service classes | `{Feature}Service` | `TrackingService` |
| Controllers | `{Feature}Controller` | `AnalyticsApiController` |
| Models | Singular PascalCase | `TrackingEvent` |
| MongoDB collections | Snake_case plural | `tracking_events` |
| MySQL tables | Snake_case plural | `audience_segments` |
| Routes | Kebab-case | `/api/v1/analytics/page-visits` |
| Events | `Module::event_name` | `Analytics::report.generated` |
| Middleware | PascalCase | `ValidateTrackingApiKey` |
| Tests | `test_{description}` | `test_can_track_page_view` |

### Multi-Tenant Query Pattern

**Every database query MUST be scoped to `tenant_id`:**

```php
// Correct
TrackingEvent::where('tenant_id', $tenantId)->get();

// WRONG — never query without tenant scope
TrackingEvent::all();
```

### Constructor Dependency Injection

```php
// Correct pattern — readonly, typed
public function __construct(
    private readonly TrackingService $trackingService,
    private readonly IdentityResolutionService $identityService,
) {}
```

### Error Handling

Use the `ApiResponse` trait for consistent JSON responses:

```php
use App\Traits\ApiResponse;

// Success
return $this->success($data, 'Operation completed');

// Error
return $this->error('Resource not found', 404);
```

### Git Workflow

```bash
# Feature branch
git checkout -b feature/my-new-feature

# Make changes, write tests
php artisan test

# Commit with descriptive message
git add .
git commit -m "feat(analytics): add revenue segmentation service"

# Push and create PR
git push origin feature/my-new-feature
```

---

## Appendix A: Quick Reference Commands

```bash
# Development
php artisan serve --port=8090          # Start server
php artisan queue:work redis           # Start queue
php artisan tinker                     # REPL

# Testing
php artisan test                       # All tests
php artisan test --filter=MyTest       # Specific test
php tests/comprehensive_e2e_test.php   # E2E tests

# Database
php artisan migrate                    # Run migrations
php artisan migrate:rollback           # Rollback last batch
php artisan db:seed                    # Run seeders

# Modules
php artisan module:list                # List modules
php artisan module:make NewModule      # Create module
php artisan module:enable NewModule    # Enable module
php artisan module:make-service Svc Module    # Create service
php artisan module:make-controller Ctrl Module # Create controller
php artisan module:make-model Model Module     # Create model
php artisan module:make-migration name Module  # Create migration

# Cache
php artisan config:cache               # Cache config
php artisan route:cache                # Cache routes
php artisan cache:clear                # Clear cache

# Debugging
php artisan route:list                 # All routes
php artisan route:list --path=api      # API routes only
php artisan pail                       # Live log viewer
```

---

## Appendix B: Module Summary Table

| Module | Services | Controllers | Models | API Endpoints | Key Features |
|---|---|---|---|---|---|
| **Analytics** | 32 | 6 | 6 | ~40 | Event tracking, identity resolution, attribution, CLV, NLQ, funnels, cohorts |
| **DataSync** | 8 (3 + 5 normalizers) | 1 | 11 | 12 | Platform sync, consent management, normalizers |
| **Marketing** | 10 | 7 | — | ~35 | Multi-channel campaigns, flows, templates, contacts |
| **Chatbot** | 5 | 1 | — | 7 | AI chat, intent detection, rage-click, order tracking |
| **AiSearch** | 5 | 1 | — | 6 | Semantic search, visual search, personalization |
| **BI** | 12 | 7 | — | ~30 | Reports, KPIs, dashboards, predictions, exports |
| **Total** | **72** | **23** | **17** | **~150** | — |

---

*End of Technical Documentation*
