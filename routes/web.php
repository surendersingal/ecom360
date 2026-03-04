<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\PageController as AdminPage;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Tenant\DashboardController as TenantDashboard;
use App\Http\Controllers\Tenant\PageController as TenantPage;
use Illuminate\Support\Facades\Route;

// ─── Public ───
Route::get('/', function () {
    return redirect()->route('login');
});

// ─── Auth ───
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ─── Admin Panel ───
Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboard::class)->name('dashboard');

    // Tenant (Store) CRUD
    Route::resource('tenants', TenantController::class);
    Route::post('tenants/{tenant}/toggle-active', [TenantController::class, 'toggleActive'])->name('tenants.toggle-active');
    Route::post('tenants/{tenant}/verify', [TenantController::class, 'verify'])->name('tenants.verify');
    Route::post('tenants/{tenant}/regenerate-key', [TenantController::class, 'regenerateApiKey'])->name('tenants.regenerate-key');

    // User CRUD
    Route::resource('users', UserController::class)->except(['show']);

    // Impersonation
    Route::get('impersonate/{tenant}', [ImpersonationController::class, 'start'])->name('impersonate.start');
    Route::get('impersonate-stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');

    // Analytics
    Route::get('analytics/platform', [AdminPage::class, 'platformAnalytics'])->name('analytics.platform');
    Route::get('analytics/tenants', [AdminPage::class, 'tenantAnalytics'])->name('analytics.tenants');
    Route::get('analytics/revenue', [AdminPage::class, 'revenueOverview'])->name('analytics.revenue');
    Route::get('analytics/benchmarking', [AdminPage::class, 'crossTenantBenchmarking'])->name('analytics.benchmarking');

    // Manage
    Route::get('roles', [AdminPage::class, 'roles'])->name('roles');

    // Monitoring
    Route::get('activity-log', [AdminPage::class, 'activityLog'])->name('activity-log');
    Route::get('system-health', [AdminPage::class, 'systemHealth'])->name('system-health');
    Route::get('queue-monitor', [AdminPage::class, 'queueMonitor'])->name('queue-monitor');
    Route::get('event-bus', [AdminPage::class, 'eventBus'])->name('event-bus');

    // Infrastructure
    Route::get('modules', [AdminPage::class, 'modules'])->name('modules');
    Route::get('data-management', [AdminPage::class, 'dataManagement'])->name('data-management');
    Route::get('datasync', [AdminPage::class, 'datasyncOverview'])->name('datasync');

    // Settings
    Route::get('settings', [AdminPage::class, 'settings'])->name('settings');
});

// ─── Tenant Panel ───
Route::middleware(['auth', 'resolve_tenant'])->prefix('app/{tenant}')->name('tenant.')->group(function () {
    Route::get('/', TenantDashboard::class)->name('dashboard');

    // ── Analytics: Traffic ──
    Route::get('realtime', [TenantPage::class, 'realtime'])->name('realtime');
    Route::get('page-visits', [TenantPage::class, 'pageVisits'])->name('page-visits');
    Route::get('sessions', [TenantPage::class, 'sessions'])->name('sessions');
    Route::get('funnels', [TenantPage::class, 'funnels'])->name('funnels');

    // ── Analytics: Revenue ──
    Route::get('products', [TenantPage::class, 'products'])->name('products');
    Route::get('categories', [TenantPage::class, 'categories'])->name('categories');
    Route::get('campaigns', [TenantPage::class, 'campaigns'])->name('campaigns');
    Route::get('revenue-waterfall', [TenantPage::class, 'revenueWaterfall'])->name('revenue-waterfall');

    // ── Analytics: Audience ──
    Route::get('customer-journey', [TenantPage::class, 'customerJourney'])->name('customer-journey');
    Route::get('cohorts', [TenantPage::class, 'cohorts'])->name('cohorts');
    Route::get('segments', [TenantPage::class, 'segments'])->name('segments');
    Route::get('geographic', [TenantPage::class, 'geographic'])->name('geographic');
    Route::get('clv', [TenantPage::class, 'clv'])->name('clv');

    // ── Analytics: AI & Insights ──
    Route::get('ai-insights', [TenantPage::class, 'aiInsights'])->name('ai-insights');
    Route::get('why-analysis', [TenantPage::class, 'whyAnalysis'])->name('why-analysis');
    Route::get('nlq', [TenantPage::class, 'nlq'])->name('nlq');
    Route::get('recommendations', [TenantPage::class, 'recommendations'])->name('recommendations');
    Route::get('benchmarks', [TenantPage::class, 'benchmarks'])->name('benchmarks');

    // ── Marketing ──
    Route::get('marketing/contacts', [TenantPage::class, 'marketingContacts'])->name('marketing.contacts');
    Route::get('marketing/campaigns', [TenantPage::class, 'marketingCampaigns'])->name('marketing.campaigns');
    Route::get('marketing/templates', [TenantPage::class, 'marketingTemplates'])->name('marketing.templates');
    Route::get('marketing/flows', [TenantPage::class, 'marketingFlows'])->name('marketing.flows');
    Route::get('marketing/flows/{flowId}/builder', [TenantPage::class, 'flowBuilder'])->name('marketing.flow-builder');
    Route::get('marketing/channels', [TenantPage::class, 'marketingChannels'])->name('marketing.channels');
    Route::get('marketing/audience-sync', [TenantPage::class, 'marketingAudienceSync'])->name('marketing.audience-sync');

    // ── Business Intelligence ──
    Route::get('bi/dashboards', [TenantPage::class, 'biDashboards'])->name('bi.dashboards');
    Route::get('bi/reports', [TenantPage::class, 'biReports'])->name('bi.reports');
    Route::get('bi/kpis', [TenantPage::class, 'biKpis'])->name('bi.kpis');
    Route::get('bi/alerts', [TenantPage::class, 'biAlerts'])->name('bi.alerts');
    Route::get('bi/predictions', [TenantPage::class, 'biPredictions'])->name('bi.predictions');
    Route::get('bi/exports', [TenantPage::class, 'biExports'])->name('bi.exports');

    // ── Monitoring ──
    Route::get('behavioral-triggers', [TenantPage::class, 'behavioralTriggers'])->name('behavioral-triggers');
    Route::get('realtime-alerts', [TenantPage::class, 'realtimeAlerts'])->name('realtime-alerts');

    // ── AI Search & Discovery (UC1-10) ──
    Route::get('search/gift-concierge', [TenantPage::class, 'giftConcierge'])->name('search.gift-concierge');
    Route::get('search/shop-the-room', [TenantPage::class, 'shopTheRoom'])->name('search.shop-the-room');
    Route::get('search/personalized-size', [TenantPage::class, 'personalizedSize'])->name('search.personalized-size');
    Route::get('search/oos-reroute', [TenantPage::class, 'oosReroute'])->name('search.oos-reroute');
    Route::get('search/typo-correction', [TenantPage::class, 'typoCorrection'])->name('search.typo-correction');
    Route::get('search/subscription-discovery', [TenantPage::class, 'subscriptionDiscovery'])->name('search.subscription-discovery');
    Route::get('search/b2b-search', [TenantPage::class, 'b2bSearch'])->name('search.b2b-search');
    Route::get('search/trend-ranking', [TenantPage::class, 'trendRanking'])->name('search.trend-ranking');
    Route::get('search/comparison', [TenantPage::class, 'comparisonSearch'])->name('search.comparison');
    Route::get('search/voice-to-cart', [TenantPage::class, 'voiceToCart'])->name('search.voice-to-cart');

    // ── Hyper-Personalized Marketing (UC11-20) ──
    Route::get('marketing/weather-campaigns', [TenantPage::class, 'weatherCampaigns'])->name('marketing.weather-campaigns');
    Route::get('marketing/payday-surge', [TenantPage::class, 'paydaySurge'])->name('marketing.payday-surge');
    Route::get('marketing/cart-downsell', [TenantPage::class, 'cartDownsell'])->name('marketing.cart-downsell');
    Route::get('marketing/ugc-incentive', [TenantPage::class, 'ugcIncentive'])->name('marketing.ugc-incentive');
    Route::get('marketing/back-in-stock', [TenantPage::class, 'backInStock'])->name('marketing.back-in-stock');
    Route::get('marketing/discount-addiction', [TenantPage::class, 'discountAddiction'])->name('marketing.discount-addiction');
    Route::get('marketing/vip-early-access', [TenantPage::class, 'vipEarlyAccess'])->name('marketing.vip-early-access');
    Route::get('marketing/churn-winback', [TenantPage::class, 'churnWinback'])->name('marketing.churn-winback');
    Route::get('marketing/replenishment', [TenantPage::class, 'replenishment'])->name('marketing.replenishment');
    Route::get('marketing/milestones', [TenantPage::class, 'milestones'])->name('marketing.milestones');

    // ── Autonomous Business Ops (UC21-30) ──
    Route::get('bi/stale-pricing', [TenantPage::class, 'stalePricing'])->name('bi.stale-pricing');
    Route::get('bi/fraud-scoring', [TenantPage::class, 'fraudScoring'])->name('bi.fraud-scoring');
    Route::get('bi/demand-forecast', [TenantPage::class, 'demandForecast'])->name('bi.demand-forecast');
    Route::get('bi/shipping-analyzer', [TenantPage::class, 'shippingAnalyzer'])->name('bi.shipping-analyzer');
    Route::get('bi/return-anomaly', [TenantPage::class, 'returnAnomaly'])->name('bi.return-anomaly');
    Route::get('bi/cannibalization', [TenantPage::class, 'cannibalization'])->name('bi.cannibalization');
    Route::get('bi/ltv-vs-cac', [TenantPage::class, 'ltvVsCac'])->name('bi.ltv-vs-cac');
    Route::get('bi/conversion-probability', [TenantPage::class, 'conversionProbability'])->name('bi.conversion-probability');
    Route::get('bi/device-revenue', [TenantPage::class, 'deviceRevenue'])->name('bi.device-revenue');
    Route::get('bi/cohort-acquisition', [TenantPage::class, 'cohortAcquisition'])->name('bi.cohort-acquisition');

    // ── Proactive Customer Support (UC31-40) ──
    Route::get('support/order-modification', [TenantPage::class, 'orderModification'])->name('support.order-modification');
    Route::get('support/sentiment-router', [TenantPage::class, 'sentimentRouter'])->name('support.sentiment-router');
    Route::get('support/vip-greeting', [TenantPage::class, 'vipGreeting'])->name('support.vip-greeting');
    Route::get('support/warranty-claims', [TenantPage::class, 'warrantyClaims'])->name('support.warranty-claims');
    Route::get('support/sizing-assistant', [TenantPage::class, 'sizingAssistant'])->name('support.sizing-assistant');
    Route::get('support/order-tracking', [TenantPage::class, 'visualOrderTracking'])->name('support.order-tracking');
    Route::get('support/objection-handler', [TenantPage::class, 'objectionHandler'])->name('support.objection-handler');
    Route::get('support/subscription-mgmt', [TenantPage::class, 'subscriptionMgmt'])->name('support.subscription-mgmt');
    Route::get('support/gift-cards', [TenantPage::class, 'giftCardBuilder'])->name('support.gift-cards');
    Route::get('support/video-reviews', [TenantPage::class, 'videoReviews'])->name('support.video-reviews');

    // ── Next-Gen Analytics & CDP (UC41-50) ──
    Route::get('cdp/offline-stitching', [TenantPage::class, 'offlineStitching'])->name('cdp.offline-stitching');
    Route::get('cdp/zombie-accounts', [TenantPage::class, 'zombieAccounts'])->name('cdp.zombie-accounts');
    Route::get('cdp/product-affinity', [TenantPage::class, 'productAffinity'])->name('cdp.product-affinity');
    Route::get('cdp/zero-party-data', [TenantPage::class, 'zeroPartyData'])->name('cdp.zero-party-data');
    Route::get('cdp/refund-impact', [TenantPage::class, 'refundImpact'])->name('cdp.refund-impact');
    Route::get('cdp/attribution', [TenantPage::class, 'multiTouchAttribution'])->name('cdp.attribution');
    Route::get('cdp/journey-replay', [TenantPage::class, 'journeyReplay'])->name('cdp.journey-replay');
    Route::get('cdp/gdpr-purge', [TenantPage::class, 'gdprPurge'])->name('cdp.gdpr-purge');
    Route::get('cdp/form-abandonment', [TenantPage::class, 'formAbandonment'])->name('cdp.form-abandonment');
    Route::get('cdp/cross-benchmarking', [TenantPage::class, 'crossBenchmarking'])->name('cdp.cross-benchmarking');

    // ── Data Sync ──
    Route::get('datasync/connections', [TenantPage::class, 'datasyncConnections'])->name('datasync.connections');
    Route::get('datasync/permissions', [TenantPage::class, 'datasyncPermissions'])->name('datasync.permissions');
    Route::get('datasync/products', [TenantPage::class, 'datasyncProducts'])->name('datasync.products');
    Route::get('datasync/categories', [TenantPage::class, 'datasyncCategories'])->name('datasync.categories');
    Route::get('datasync/orders', [TenantPage::class, 'datasyncOrders'])->name('datasync.orders');
    Route::get('datasync/customers', [TenantPage::class, 'datasyncCustomers'])->name('datasync.customers');
    Route::get('datasync/inventory', [TenantPage::class, 'datasyncInventory'])->name('datasync.inventory');
    Route::get('datasync/logs', [TenantPage::class, 'datasyncLogs'])->name('datasync.logs');

    // ── Developer ──
    Route::get('custom-events', [TenantPage::class, 'customEvents'])->name('custom-events');
    Route::get('webhooks', [TenantPage::class, 'webhooks'])->name('webhooks');
    Route::get('integration', [TenantPage::class, 'integration'])->name('integration');

    // ── Settings ──
    Route::get('settings', [TenantPage::class, 'settings'])->name('settings');
});
