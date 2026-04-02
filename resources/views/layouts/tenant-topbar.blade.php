@php
    $slug = $tenant->slug;
    $user = auth()->user();
    $isSuperAdmin = $user?->is_super_admin ?? false;
    $initials = collect(explode(' ', $user->name ?? 'U'))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->implode('');

    // Permission checks
    $canAnalytics       = $isSuperAdmin || $user?->can('analytics.view');
    $canSearch          = $isSuperAdmin || $user?->can('ai_search.query');
    $canMarketing       = $isSuperAdmin || $user?->can('marketing.view');
    $canBI              = $isSuperAdmin || $user?->can('business_intelligence.view');
    $canChatbot         = $isSuperAdmin || $user?->can('chatbot.view');
    $canManage          = $isSuperAdmin || $user?->can('analytics.manage');

    // Active section detection
    $r = request()->route()?->getName() ?? '';
    $sec = 'dashboard';
    if (str_contains($r, 'realtime') && !str_contains($r, 'alert') && !str_contains($r, 'analytics.')) $sec = 'dashboard';
    elseif (str_contains($r, 'analytics.') || str_contains($r, 'traffic') || str_contains($r, 'revenue') || str_contains($r, 'audience') || str_contains($r, 'sessions') || str_contains($r, 'page-visit') || str_contains($r, 'funnel') || str_contains($r, 'product') || str_contains($r, 'categori') || str_contains($r, 'campaign') || str_contains($r, 'waterfall') || str_contains($r, 'customer-journey') || str_contains($r, 'cohort') || str_contains($r, 'segment') || str_contains($r, 'geographic') || str_contains($r, 'clv') || str_contains($r, 'ai-insight') || str_contains($r, 'why-analysis') || str_contains($r, 'nlq') || str_contains($r, 'recommendation') || str_contains($r, 'benchmark') || str_contains($r, 'behavioral') || str_contains($r, 'realtime-alert')) $sec = 'analytics';
    elseif (str_contains($r, 'search')) $sec = 'search';
    elseif (str_contains($r, 'marketing')) $sec = 'marketing';
    elseif (str_contains($r, 'bi.') || str_contains($r, 'bi-')) $sec = 'bi';
    elseif (str_contains($r, 'chatbot') || str_contains($r, 'support')) $sec = 'support';
    elseif (str_contains($r, 'datasync') || str_contains($r, 'sync')) $sec = 'datasync';
    elseif (str_contains($r, 'cdp')) $sec = 'cdp';
    elseif (str_contains($r, 'webhook') || str_contains($r, 'integration') || str_contains($r, 'custom-event')) $sec = 'developer';
    elseif (str_contains($r, 'setting')) $sec = 'settings';
@endphp

{{-- ════════════════════════════════════════════════════════════════════
     ENTERPRISE HEADER — Two rows: brand bar + mega-menu navigation
     ════════════════════════════════════════════════════════════════════ --}}
{{-- Critical inline styles to guarantee mega-menu works even with cached CSS --}}
<style>
    .e360-tenant #page-topbar { position:fixed!important; top:0!important; left:0!important; right:0!important; height:auto!important; z-index:1050!important; overflow:visible!important; background:transparent!important; border:none!important; box-shadow:none!important; }
    .e360-tenant .e360-header { position:fixed; top:0; left:0; right:0; z-index:1050; background:#fff; overflow:visible!important; border-bottom:1px solid #e5e7eb; box-shadow:0 1px 4px rgba(0,0,0,0.05); }
    .e360-tenant .e360-megabar { display:flex; align-items:center; height:40px; overflow:visible!important; position:relative; z-index:1060; }
    .e360-tenant .e360-megamenu { display:flex; align-items:center; list-style:none; margin:0; padding:0; height:100%; overflow:visible!important; }
    .e360-tenant .e360-mega-item { position:relative; height:100%; display:flex; align-items:center; z-index:1060; }
    .e360-tenant .e360-mega-item > a { display:flex; align-items:center; gap:5px; padding:0 12px; height:100%; font-size:12.5px; font-weight:500; color:#4b5563; text-decoration:none!important; white-space:nowrap; cursor:pointer; pointer-events:auto; border-bottom:2px solid transparent; }
    .e360-tenant .e360-mega-dropdown { position:absolute; top:100%; left:0; z-index:9999; display:none; padding:16px; background:#fff; border:1px solid #e5e7eb; border-top:2px solid #1a56db; border-radius:0 0 10px 10px; box-shadow:0 12px 40px rgba(0,0,0,0.12); pointer-events:auto; }
    .e360-tenant .e360-mega-item:hover > .e360-mega-dropdown { display:flex!important; }
    .e360-tenant .e360-mega-item:hover > a { color:#1a56db; border-bottom-color:#1a56db; }
    .e360-tenant .e360-mega-col > a { display:flex; align-items:center; gap:8px; padding:6px 8px; font-size:12.5px; color:#4b5563; text-decoration:none!important; border-radius:5px; cursor:pointer; pointer-events:auto; }
    .e360-tenant .e360-mega-col > a:hover { background:rgba(26,86,219,0.06); color:#1a56db; }
    .e360-tenant .vertical-menu, .e360-tenant .navbar-brand-box { display:none!important; }
    .e360-tenant .main-content { margin-left:0!important; }
    .e360-tenant .page-content { padding-top:92px!important; }
    .e360-tenant .e360-mega-item:nth-last-child(-n+4) .e360-mega-dropdown { left:auto; right:0; }
</style>
<header id="page-topbar" class="e360-header">
    {{-- ─── Row 1: Brand bar ─── --}}
    <div class="e360-brandbar">
        {{-- Mobile hamburger --}}
        <button class="e360-mobile-toggle d-md-none" onclick="window.ecom360ToggleMobileMenu()" title="Menu">
            <i class="bx bx-menu" style="font-size:20px"></i>
        </button>

        <a href="{{ route('tenant.dashboard', $slug) }}" class="e360-brand">
            <span class="e360-brand-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
            </span>
            <span class="e360-brand-text">ecom<b>360</b></span>
        </a>

        <span class="e360-hdr-sep"></span>
        <span class="e360-store-label">
            <span class="e360-store-dot"></span>
            {{ Str::limit($tenant->name, 28) }}
        </span>

        <div class="e360-brandbar-right">
            {{-- Search --}}
            <button class="e360-hdr-btn e360-search-btn" title="Search (⌘K)">
                <i class="bx bx-search" style="font-size:17px"></i>
                <span class="e360-kbd">⌘K</span>
            </button>

            {{-- Notifications --}}
            <div class="dropdown">
                <button class="e360-hdr-btn" data-bs-toggle="dropdown" title="Notifications">
                    <i class="bx bx-bell" style="font-size:18px"></i>
                    <span class="e360-notif-dot"></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end" style="width:300px;border-radius:10px;padding:0;">
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:14px;">Notifications</strong>
                        <a href="#" class="text-muted" style="font-size:12px;">Mark all read</a>
                    </div>
                    <div class="text-center py-4 text-muted">
                        <i class="bx bx-bell-off" style="font-size:24px;display:block;margin-bottom:4px;"></i>
                        <span style="font-size:13px;">No new notifications</span>
                    </div>
                </div>
            </div>

            <span class="e360-hdr-sep"></span>

            {{-- User --}}
            <div class="dropdown">
                <button class="e360-user-btn" data-bs-toggle="dropdown">
                    <span class="e360-avatar">{{ $initials }}</span>
                    <span class="e360-uname d-none d-md-inline">{{ $user->name ?? 'User' }}</span>
                    <i class="bx bx-chevron-down" style="font-size:14px;color:var(--neutral-400);"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end" style="width:220px;border-radius:10px;padding:6px;">
                    <div style="padding:10px 12px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);margin-bottom:4px;">
                        <span class="e360-avatar" style="width:34px;height:34px;font-size:12px;">{{ $initials }}</span>
                        <div>
                            <div style="font-weight:600;font-size:13px;color:var(--neutral-800);">{{ $user->name ?? 'User' }}</div>
                            <div style="font-size:11px;color:var(--neutral-400);">{{ $user->email ?? '' }}</div>
                        </div>
                    </div>
                    <a class="dropdown-item" href="{{ route('tenant.settings', $slug) }}" style="border-radius:6px;font-size:13px;padding:7px 12px;"><i class="bx bx-cog me-2"></i>Store Settings</a>
                    <a class="dropdown-item" href="#" style="border-radius:6px;font-size:13px;padding:7px 12px;"><i class="bx bx-user me-2"></i>Account</a>
                    <a class="dropdown-item" href="#" style="border-radius:6px;font-size:13px;padding:7px 12px;"><i class="bx bx-book-open me-2"></i>API Docs</a>
                    <div class="dropdown-divider" style="margin:4px 0;"></div>
                    <a class="dropdown-item text-danger" href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" style="border-radius:6px;font-size:13px;padding:7px 12px;">
                        <i class="bx bx-log-out me-2"></i>Sign Out
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">@csrf</form>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Row 2: Mega-menu navigation ─── --}}
    <nav class="e360-megabar">
        <ul class="e360-megamenu">
            {{-- Dashboard --}}
            <li class="e360-mega-item {{ $sec === 'dashboard' ? 'active' : '' }}">
                <a href="{{ route('tenant.dashboard', $slug) }}">
                    <i class="bx bxs-dashboard"></i> Dashboard
                </a>
                <div class="e360-mega-dropdown e360-mega-sm">
                    <div class="e360-mega-col">
                        <a href="{{ route('tenant.dashboard', $slug) }}" class="{{ request()->routeIs('tenant.dashboard') ? 'active' : '' }}"><i class="bx bxs-dashboard"></i> Overview</a>
                        <a href="{{ route('tenant.realtime', $slug) }}" class="{{ request()->routeIs('tenant.realtime') ? 'active' : '' }}"><i class="bx bx-pulse"></i> Real-Time</a>
                    </div>
                </div>
            </li>

            {{-- Analytics --}}
            @if($canAnalytics)
            <li class="e360-mega-item has-dropdown {{ $sec === 'analytics' ? 'active' : '' }}">
                <a href="{{ route('tenant.analytics.overview', $slug) }}">
                    <i class="bx bx-line-chart"></i> Analytics <i class="bx bx-chevron-down" style="font-size:10px;margin-left:2px;opacity:.6;"></i>
                </a>
                <div class="e360-mega-dropdown" style="min-width:560px;gap:24px;">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Visitors</div>
                        <a href="{{ route('tenant.analytics.overview', $slug) }}"><i class="bx bx-grid-alt"></i> Dashboard</a>
                        <a href="{{ route('tenant.analytics.visitors', $slug) }}"><i class="bx bx-user"></i> Overview</a>
                        <a href="{{ route('tenant.analytics.realtime', $slug) }}"><i class="bx bx-pulse"></i> Real-Time</a>
                        <a href="{{ route('tenant.analytics.devices', $slug) }}"><i class="bx bx-devices"></i> Devices</a>
                        <a href="{{ route('tenant.analytics.locations', $slug) }}"><i class="bx bx-map"></i> Locations</a>
                        <a href="{{ route('tenant.analytics.times', $slug) }}"><i class="bx bx-time-five"></i> Times</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Behaviour</div>
                        <a href="{{ route('tenant.analytics.pages', $slug) }}"><i class="bx bx-file"></i> Pages</a>
                        <a href="{{ route('tenant.analytics.events', $slug) }}"><i class="bx bx-pointer"></i> Events</a>
                        <a href="{{ route('tenant.analytics.site-search', $slug) }}"><i class="bx bx-search"></i> Site Search</a>
                        <div class="e360-mega-heading" style="margin-top:10px;">Acquisition</div>
                        <a href="{{ route('tenant.analytics.channels', $slug) }}"><i class="bx bx-git-merge"></i> Channels</a>
                        <a href="{{ route('tenant.analytics.campaigns', $slug) }}"><i class="bx bx-target-lock"></i> Campaigns</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Ecommerce</div>
                        <a href="{{ route('tenant.analytics.ecommerce', $slug) }}"><i class="bx bx-cart"></i> Overview</a>
                        <a href="{{ route('tenant.analytics.products', $slug) }}"><i class="bx bx-package"></i> Products</a>
                        <a href="{{ route('tenant.analytics.categories', $slug) }}"><i class="bx bx-category"></i> Categories</a>
                        <a href="{{ route('tenant.analytics.funnel', $slug) }}"><i class="bx bx-filter-alt"></i> Funnel</a>
                        <a href="{{ route('tenant.analytics.abandoned-carts', $slug) }}"><i class="bx bx-cart-alt"></i> Abandoned Carts</a>
                        <div class="e360-mega-heading" style="margin-top:10px;">AI</div>
                        <a href="{{ route('tenant.analytics.ai-insights', $slug) }}"><i class="bx bx-brain"></i> AI Insights</a>
                        <a href="{{ route('tenant.analytics.ask', $slug) }}"><i class="bx bx-message-dots"></i> Ask AI</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- AI Search --}}
            @if($canSearch)
            <li class="e360-mega-item {{ $sec === 'search' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-search-alt-2"></i> AI Search <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-md">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Core</div>
                        <a href="{{ route('tenant.search.settings', $slug) }}"><i class="bx bx-cog"></i> Search Settings</a>
                        <a href="{{ route('tenant.search.analytics', $slug) }}"><i class="bx bx-bar-chart"></i> Search Analytics</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Smart Search</div>
                        <a href="{{ route('tenant.search.gift-concierge', $slug) }}"><i class="bx bx-gift"></i> Gift Concierge</a>
                        <a href="{{ route('tenant.search.shop-the-room', $slug) }}"><i class="bx bx-image"></i> Shop the Room</a>
                        <a href="{{ route('tenant.search.personalized-size', $slug) }}"><i class="bx bx-ruler"></i> Size Filtering</a>
                        <a href="{{ route('tenant.search.oos-reroute', $slug) }}"><i class="bx bx-refresh"></i> OOS Rerouting</a>
                        <a href="{{ route('tenant.search.typo-correction', $slug) }}"><i class="bx bx-text"></i> Typo Correction</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Advanced Search</div>
                        <a href="{{ route('tenant.search.subscription-discovery', $slug) }}"><i class="bx bx-repeat"></i> Subscriptions</a>
                        <a href="{{ route('tenant.search.b2b-search', $slug) }}"><i class="bx bx-buildings"></i> B2B Search</a>
                        <a href="{{ route('tenant.search.trend-ranking', $slug) }}"><i class="bx bx-trending-up"></i> Trend Ranking</a>
                        <a href="{{ route('tenant.search.comparison', $slug) }}"><i class="bx bx-columns"></i> Comparison Matrix</a>
                        <a href="{{ route('tenant.search.voice-to-cart', $slug) }}"><i class="bx bx-microphone"></i> Voice to Cart</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Marketing --}}
            @if($canMarketing)
            <li class="e360-mega-item {{ $sec === 'marketing' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-rocket"></i> Marketing <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-lg">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Core</div>
                        <a href="{{ route('tenant.marketing.contacts', $slug) }}"><i class="bx bx-user-circle"></i> Contacts</a>
                        <a href="{{ route('tenant.marketing.campaigns', $slug) }}"><i class="bx bx-send"></i> All Campaigns</a>
                        <a href="{{ route('tenant.marketing.templates', $slug) }}"><i class="bx bx-layout"></i> Templates</a>
                        <a href="{{ route('tenant.marketing.flows', $slug) }}"><i class="bx bx-git-branch"></i> Automation Flows</a>
                        <a href="{{ route('tenant.marketing.channels', $slug) }}"><i class="bx bx-broadcast"></i> Channels</a>
                        <a href="{{ route('tenant.marketing.audience-sync', $slug) }}"><i class="bx bx-sync"></i> Audience Sync</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Hyper-Personalization</div>
                        <a href="{{ route('tenant.marketing.weather-campaigns', $slug) }}"><i class="bx bx-cloud"></i> Weather Campaigns</a>
                        <a href="{{ route('tenant.marketing.payday-surge', $slug) }}"><i class="bx bx-wallet"></i> Payday Surge</a>
                        <a href="{{ route('tenant.marketing.cart-downsell', $slug) }}"><i class="bx bx-cart"></i> Cart Down-Sell</a>
                        <a href="{{ route('tenant.marketing.ugc-incentive', $slug) }}"><i class="bx bx-camera"></i> UGC Incentives</a>
                        <a href="{{ route('tenant.marketing.back-in-stock', $slug) }}"><i class="bx bx-package"></i> Back in Stock</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Lifecycle</div>
                        <a href="{{ route('tenant.marketing.discount-addiction', $slug) }}"><i class="bx bx-purchase-tag"></i> Discount Addiction</a>
                        <a href="{{ route('tenant.marketing.vip-early-access', $slug) }}"><i class="bx bx-crown"></i> VIP Early Access</a>
                        <a href="{{ route('tenant.marketing.churn-winback', $slug) }}"><i class="bx bx-undo"></i> Churn Winback</a>
                        <a href="{{ route('tenant.marketing.replenishment', $slug) }}"><i class="bx bx-recycle"></i> Replenishment</a>
                        <a href="{{ route('tenant.marketing.milestones', $slug) }}"><i class="bx bx-trophy"></i> Milestones</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Business Intelligence --}}
            @if($canBI)
            <li class="e360-mega-item {{ $sec === 'bi' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-bar-chart-alt-2"></i> Intelligence <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-xl">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Intelligence</div>
                        <a href="{{ route('tenant.bi.revenue', $slug) }}"><i class="bx bx-wallet"></i> Revenue Center</a>
                        <a href="{{ route('tenant.bi.products', $slug) }}"><i class="bx bx-package"></i> Product Intel</a>
                        <a href="{{ route('tenant.bi.customers', $slug) }}"><i class="bx bx-user-circle"></i> Customer Intel</a>
                        <a href="{{ route('tenant.bi.cohorts', $slug) }}"><i class="bx bx-grid-alt"></i> Cohort Retention</a>
                        <a href="{{ route('tenant.bi.operations', $slug) }}"><i class="bx bx-cog"></i> Operations</a>
                        <a href="{{ route('tenant.bi.coupons', $slug) }}"><i class="bx bx-purchase-tag"></i> Coupon Intel</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Cross-Module</div>
                        <a href="{{ route('tenant.bi.attribution', $slug) }}"><i class="bx bx-target-lock"></i> Marketing ROI</a>
                        <a href="{{ route('tenant.bi.search-revenue', $slug) }}"><i class="bx bx-search-alt-2"></i> Search Revenue</a>
                        <a href="{{ route('tenant.bi.chatbot-impact', $slug) }}"><i class="bx bx-bot"></i> Chatbot Impact</a>
                        <a href="{{ route('tenant.bi.copilot', $slug) }}"><i class="bx bx-brain"></i> AI Copilot</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Core BI</div>
                        <a href="{{ route('tenant.bi.dashboards', $slug) }}"><i class="bx bxs-bar-chart-alt-2"></i> Dashboards</a>
                        <a href="{{ route('tenant.bi.reports', $slug) }}"><i class="bx bx-file-find"></i> Reports</a>
                        <a href="{{ route('tenant.bi.kpis', $slug) }}"><i class="bx bx-tachometer"></i> KPI Tracker</a>
                        <a href="{{ route('tenant.bi.alerts', $slug) }}"><i class="bx bx-bell"></i> Alerts</a>
                        <a href="{{ route('tenant.bi.exports', $slug) }}"><i class="bx bx-export"></i> Data Exports</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Autonomous Ops</div>
                        <a href="{{ route('tenant.bi.stale-pricing', $slug) }}"><i class="bx bx-dollar"></i> Stale Pricing</a>
                        <a href="{{ route('tenant.bi.fraud-scoring', $slug) }}"><i class="bx bx-shield"></i> Fraud Scoring</a>
                        <a href="{{ route('tenant.bi.demand-forecast', $slug) }}"><i class="bx bx-line-chart"></i> Demand Forecast</a>
                        <a href="{{ route('tenant.bi.shipping-analyzer', $slug) }}"><i class="bx bx-car"></i> Shipping Analyzer</a>
                        <a href="{{ route('tenant.bi.return-anomaly', $slug) }}"><i class="bx bx-error"></i> Return Anomalies</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Advanced BI</div>
                        <a href="{{ route('tenant.bi.cannibalization', $slug) }}"><i class="bx bx-intersect"></i> Cannibalization</a>
                        <a href="{{ route('tenant.bi.ltv-vs-cac', $slug) }}"><i class="bx bx-transfer"></i> LTV vs CAC</a>
                        <a href="{{ route('tenant.bi.conversion-probability', $slug) }}"><i class="bx bx-target-lock"></i> Conversion Score</a>
                        <a href="{{ route('tenant.bi.device-revenue', $slug) }}"><i class="bx bx-devices"></i> Device Revenue</a>
                        <a href="{{ route('tenant.bi.cohort-acquisition', $slug) }}"><i class="bx bx-group"></i> Source Cohorts</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Support --}}
            @if($canChatbot)
            <li class="e360-mega-item {{ $sec === 'support' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-support"></i> Support <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-lg">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Chatbot</div>
                        <a href="{{ route('tenant.chatbot.settings', $slug) }}"><i class="bx bx-cog"></i> Chatbot Settings</a>
                        <a href="{{ route('tenant.chatbot.flows', $slug) }}"><i class="bx bx-git-branch"></i> Flow Builder</a>
                        <a href="{{ route('tenant.chatbot.conversations', $slug) }}"><i class="bx bx-conversation"></i> Conversations</a>
                        <a href="{{ route('tenant.chatbot.analytics', $slug) }}"><i class="bx bx-bar-chart"></i> Chatbot Analytics</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Proactive Support</div>
                        <a href="{{ route('tenant.support.order-modification', $slug) }}"><i class="bx bx-edit"></i> Order Modification</a>
                        <a href="{{ route('tenant.support.sentiment-router', $slug) }}"><i class="bx bx-happy"></i> Sentiment Router</a>
                        <a href="{{ route('tenant.support.vip-greeting', $slug) }}"><i class="bx bx-crown"></i> VIP Greeting</a>
                        <a href="{{ route('tenant.support.warranty-claims', $slug) }}"><i class="bx bx-shield-quarter"></i> Warranty Claims</a>
                        <a href="{{ route('tenant.support.sizing-assistant', $slug) }}"><i class="bx bx-body"></i> Sizing Assistant</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Advanced Chat</div>
                        <a href="{{ route('tenant.support.order-tracking', $slug) }}"><i class="bx bx-map-pin"></i> Order Tracking</a>
                        <a href="{{ route('tenant.support.objection-handler', $slug) }}"><i class="bx bx-message-x"></i> Objection Handler</a>
                        <a href="{{ route('tenant.support.subscription-mgmt', $slug) }}"><i class="bx bx-repeat"></i> Subscriptions</a>
                        <a href="{{ route('tenant.support.gift-cards', $slug) }}"><i class="bx bx-gift"></i> Gift Cards</a>
                        <a href="{{ route('tenant.support.video-reviews', $slug) }}"><i class="bx bx-video"></i> Video Reviews</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- CDP --}}
            @if($canBI)
            <li class="e360-mega-item {{ $sec === 'cdp' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-data"></i> CDP <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-md">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Customer Data Platform</div>
                        <a href="{{ route('tenant.cdp.dashboard', $slug) }}"><i class="bx bx-tachometer"></i> CDP Dashboard</a>
                        <a href="{{ route('tenant.cdp.profiles', $slug) }}"><i class="bx bx-group"></i> Customer Profiles</a>
                        <a href="{{ route('tenant.cdp.segments', $slug) }}"><i class="bx bx-target-lock"></i> Segments</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Intelligence</div>
                        <a href="{{ route('tenant.cdp.rfm', $slug) }}"><i class="bx bx-grid-alt"></i> RFM Analysis</a>
                        <a href="{{ route('tenant.cdp.predictions', $slug) }}"><i class="bx bx-brain"></i> Predictions</a>
                        <a href="{{ route('tenant.cdp.data-health', $slug) }}"><i class="bx bx-check-shield"></i> Data Health</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Data Sync --}}
            @if($canManage)
            <li class="e360-mega-item {{ $sec === 'datasync' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-transfer-alt"></i> Data Sync <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-md">
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Connections</div>
                        <a href="{{ route('tenant.datasync.connections', $slug) }}"><i class="bx bx-plug"></i> Connections</a>
                        <a href="{{ route('tenant.datasync.permissions', $slug) }}"><i class="bx bx-lock-open-alt"></i> Permissions</a>
                        <a href="{{ route('tenant.datasync.logs', $slug) }}"><i class="bx bx-history"></i> Sync Logs</a>
                        <a href="{{ route('tenant.datasync.settings', $slug) }}"><i class="bx bx-cog"></i> Sync Settings</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Catalog Data</div>
                        <a href="{{ route('tenant.datasync.products', $slug) }}"><i class="bx bx-cube"></i> Products</a>
                        <a href="{{ route('tenant.datasync.categories', $slug) }}"><i class="bx bx-category"></i> Categories</a>
                        <a href="{{ route('tenant.datasync.inventory', $slug) }}"><i class="bx bx-box"></i> Inventory</a>
                    </div>
                    <div class="e360-mega-col">
                        <div class="e360-mega-heading">Customer Data</div>
                        <a href="{{ route('tenant.datasync.orders', $slug) }}"><i class="bx bx-receipt"></i> Orders</a>
                        <a href="{{ route('tenant.datasync.customers', $slug) }}"><i class="bx bx-user-check"></i> Customers</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Developer --}}
            @if($canManage)
            <li class="e360-mega-item {{ $sec === 'developer' ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <i class="bx bx-code-alt"></i> Developer <i class="bx bx-chevron-down e360-chev"></i>
                </a>
                <div class="e360-mega-dropdown e360-mega-sm">
                    <div class="e360-mega-col">
                        <a href="{{ route('tenant.custom-events', $slug) }}"><i class="bx bx-bolt-circle"></i> Custom Events</a>
                        <a href="{{ route('tenant.webhooks', $slug) }}"><i class="bx bx-link-external"></i> Webhooks</a>
                        <a href="{{ route('tenant.integration', $slug) }}"><i class="bx bx-code-alt"></i> Integration & SDK</a>
                    </div>
                </div>
            </li>
            @endif

            {{-- Settings --}}
            @if($canManage)
            <li class="e360-mega-item {{ $sec === 'settings' ? 'active' : '' }}">
                <a href="{{ route('tenant.settings', $slug) }}">
                    <i class="bx bx-cog"></i> Settings
                </a>
            </li>
            @endif
        </ul>
    </nav>
</header>
