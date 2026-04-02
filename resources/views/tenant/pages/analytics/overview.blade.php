@extends('layouts.tenant')

@section('title', 'Analytics Dashboard')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-line-chart" style="color:var(--analytics);"></i> Analytics</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Analytics</li>
                </ol>
            </nav>
        </div>
        <div class="header-actions d-flex align-items-center gap-2">
            <div class="e360-period-toggle">
                <button class="period-btn {{ request('date_range', '30d') === '7d' ? 'active' : '' }}" data-range="7d">7D</button>
                <button class="period-btn {{ request('date_range', '30d') === '30d' ? 'active' : '' }}" data-range="30d">30D</button>
                <button class="period-btn {{ request('date_range', '30d') === '90d' ? 'active' : '' }}" data-range="90d">90D</button>
                <button class="period-btn {{ request('date_range', '30d') === '365d' ? 'active' : '' }}" data-range="365d">1Y</button>
            </div>
            <span class="e360-live-badge" style="font-size:12px;"><span class="live-dot"></span> Live</span>
        </div>
    </div>

    {{-- Main Overview Content --}}
    <div class="e360-analytics-body">

        {{-- KPI Summary Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Visits</span>
                        <span class="kpi-icon visitors"><i class="bx bx-user"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-visits">—</div>
                    <div class="kpi-trend" id="kpi-visits-trend"></div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Unique Visitors</span>
                        <span class="kpi-icon orders"><i class="bx bx-body"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-unique">—</div>
                    <div class="kpi-trend" id="kpi-unique-trend"></div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Pageviews</span>
                        <span class="kpi-icon revenue"><i class="bx bx-file"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-pageviews">—</div>
                    <div class="kpi-trend" id="kpi-pageviews-trend"></div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Bounce Rate</span>
                        <span class="kpi-icon conversion"><i class="bx bx-log-out"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-bounce">—</div>
                    <div class="kpi-trend" id="kpi-bounce-trend"></div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Avg. Duration</span>
                        <span class="kpi-icon"><i class="bx bx-time-five" style="color:var(--chatbot);"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-duration">—</div>
                    <div class="kpi-trend" id="kpi-duration-trend"></div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card" data-module="analytics">
                    <div class="kpi-header">
                        <span class="kpi-label">Revenue</span>
                        <span class="kpi-icon revenue"><i class="bx bx-dollar-circle"></i></span>
                    </div>
                    <div class="kpi-value" id="kpi-revenue">—</div>
                    <div class="kpi-trend" id="kpi-revenue-trend"></div>
                </div>
            </div>
        </div>

        {{-- Chart Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Visits Over Time</h5>
                            <div class="e360-period-toggle" id="chart-metric-toggle">
                                <button class="period-btn active" data-metric="visits">Visits</button>
                                <button class="period-btn" data-metric="pageviews">Pageviews</button>
                                <button class="period-btn" data-metric="revenue">Revenue</button>
                            </div>
                        </div>
                        <div id="visits-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Traffic Sources</h5>
                        <div id="sources-chart" style="height:280px;"></div>
                        <div id="sources-legend" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Content Row: Pages + Referrers + Devices --}}
        <div class="row g-3 mb-4">
            {{-- Popular Pages (Matomo's "Pages" widget) --}}
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Pages</h5>
                            <a href="{{ route('tenant.analytics.pages', $tenant->slug) }}" class="text-primary" style="font-size:12px;">View All →</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-nowrap mb-0" id="pages-mini-table">
                                <thead><tr><th>Page</th><th class="text-end">Views</th><th class="text-end">Avg. Time</th></tr></thead>
                                <tbody id="pages-mini-body">
                                    <tr><td colspan="3" class="text-center text-muted py-3"><div class="skeleton-text" style="width:80%;margin:0 auto;"></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Top Referrers --}}
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Referrers</h5>
                            <a href="{{ route('tenant.analytics.referrers', $tenant->slug) }}" class="text-primary" style="font-size:12px;">View All →</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-nowrap mb-0">
                                <thead><tr><th>Source</th><th class="text-end">Visits</th><th class="text-end">Conv. %</th></tr></thead>
                                <tbody id="referrers-mini-body">
                                    <tr><td colspan="3" class="text-center text-muted py-3"><div class="skeleton-text" style="width:80%;margin:0 auto;"></div></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Devices Breakdown --}}
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Devices</h5>
                            <a href="{{ route('tenant.analytics.devices', $tenant->slug) }}" class="text-primary" style="font-size:12px;">View All →</a>
                        </div>
                        <div id="devices-chart" style="height:240px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row: Ecommerce Summary + Conversion Funnel --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Ecommerce Summary</h5>
                            <a href="{{ route('tenant.analytics.ecommerce', $tenant->slug) }}" class="text-primary" style="font-size:12px;">Details →</a>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-4 text-center">
                                <div style="font-size:11px;color:var(--neutral-500);text-transform:uppercase;letter-spacing:0.5px;">Orders</div>
                                <div class="mono" style="font-size:22px;font-weight:700;color:var(--neutral-900);" id="ecom-orders">—</div>
                            </div>
                            <div class="col-4 text-center">
                                <div style="font-size:11px;color:var(--neutral-500);text-transform:uppercase;letter-spacing:0.5px;">Revenue</div>
                                <div class="mono" style="font-size:22px;font-weight:700;color:var(--success);" id="ecom-revenue">—</div>
                            </div>
                            <div class="col-4 text-center">
                                <div style="font-size:11px;color:var(--neutral-500);text-transform:uppercase;letter-spacing:0.5px;">Avg. Order</div>
                                <div class="mono" style="font-size:22px;font-weight:700;color:var(--primary-500);" id="ecom-aov">—</div>
                            </div>
                        </div>
                        <div id="revenue-sparkline" style="height:160px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Conversion Funnel</h5>
                            <a href="{{ route('tenant.analytics.funnel', $tenant->slug) }}" class="text-primary" style="font-size:12px;">Full View →</a>
                        </div>
                        <div id="funnel-chart" style="height:260px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row: Real-Time Mini + AI Insights + Geographic --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3">
                <div class="card" data-module="analytics" style="height:100%;background:linear-gradient(135deg,var(--neutral-900),#1E293B);">
                    <div class="card-body text-white">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="card-title mb-0 text-white" style="font-size:14px;">Real-Time</h5>
                            <span class="e360-live-badge" style="font-size:10px;"><span class="live-dot"></span> Live</span>
                        </div>
                        <div class="text-center my-3">
                            <div id="rt-count" style="font-family:'JetBrains Mono';font-size:56px;font-weight:700;line-height:1;">0</div>
                            <div style="font-size:12px;opacity:0.7;">active visitors</div>
                        </div>
                        <div class="d-flex justify-content-between" style="font-size:12px;opacity:0.8;">
                            <span><i class="bx bx-pulse"></i> <span id="rt-epm">0</span>/min</span>
                            <span><i class="bx bx-globe"></i> <span id="rt-countries">0</span> countries</span>
                        </div>
                        <div class="text-center mt-3">
                            <a href="{{ route('tenant.analytics.realtime', $tenant->slug) }}" class="btn btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.2);font-size:11px;">
                                Open Real-Time →
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0"><i class="bx bx-brain" style="color:var(--chatbot);"></i> AI Insights</h5>
                            <a href="{{ route('tenant.analytics.ai-insights', $tenant->slug) }}" class="text-primary" style="font-size:12px;">All Insights →</a>
                        </div>
                        <div id="ai-insights-list" style="max-height:220px;overflow-y:auto;">
                            <div class="text-center text-muted py-4" style="font-size:13px;">
                                <i class="bx bx-loader-alt bx-spin" style="font-size:20px;"></i><br>
                                Generating insights...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Top Countries</h5>
                            <a href="{{ route('tenant.analytics.locations', $tenant->slug) }}" class="text-primary" style="font-size:12px;">Map →</a>
                        </div>
                        <div id="countries-list" style="max-height:220px;overflow-y:auto;">
                            <div class="skeleton-text" style="width:70%;margin:8px 0;"></div>
                            <div class="skeleton-text" style="width:55%;margin:8px 0;"></div>
                            <div class="skeleton-text" style="width:40%;margin:8px 0;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection

@section('script-bottom')
<script>
(function() {
    'use strict';

    const API = EcomAPI.baseUrl + '/analytics';
    const range = new URLSearchParams(window.location.search).get('date_range') || '30d';
    const fmt = EcomUtils;

    // ── Date range switcher ──
    document.querySelectorAll('.header-actions .period-btn[data-range]').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('date_range', this.dataset.range);
            window.location.href = url.toString();
        });
    });

    // ── Chart defaults ──
    const chartDefaults = {
        chart: { fontFamily: 'Inter, sans-serif', toolbar: { show: false } },
        colors: ['#1A56DB', '#10B981', '#F59E0B', '#7C3AED', '#0891B2', '#EF4444'],
        stroke: { curve: 'smooth', width: 2 },
        grid: { borderColor: '#E2E8F0', strokeDashArray: 4, padding: { left: 8, right: 8 } },
        tooltip: { theme: 'light' },
    };

    // Store geo data for realtime refresh
    let _geoData = null;

    // ── Fetch overview data (each with .catch so one failure doesn't kill all) ──
    $.when(
        $.get(API + '/overview', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/sessions', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/revenue', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/geographic', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/realtime').catch(()=>[{}]),
        $.get(API + '/funnel', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/campaigns', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/all-pages', { date_range: range }).catch(()=>[{}])
    ).then(function(overviewRes, sessionsRes, revenueRes, geoRes, rtRes, funnelRes, campaignsRes, pagesRes) {
        function ex(r) { if (!r) return {}; const d = Array.isArray(r) ? r[0] : r; return d?.data || d || {}; }
        const ov = ex(overviewRes);
        const sess = ex(sessionsRes);
        const rv = ex(revenueRes);
        const geo = ex(geoRes);
        const rt = ex(rtRes);
        const fn = ex(funnelRes);
        const camp = ex(campaignsRes);
        const pgs = ex(pagesRes);

        _geoData = geo; // Save for realtime refresh

        console.log('Analytics data loaded:', {ov, sess, rv, geo, rt, fn, camp, pgs});

        renderKpis(ov, sess, rv);
        renderVisitsChart(sess);
        renderSourcesChart(camp, ov, sess);
        renderDevicesChart(geo);
        renderPages(pgs);
        renderReferrers(camp);
        renderEcommerce(rv);
        renderFunnel(fn);
        renderCountries(geo);
        renderRealtime(rt, geo);
    }).fail(function(e) {
        console.warn('Analytics API fetch failed', e);
    });

    // ── AI Insights (separate call) ──
    $.get(API + '/advanced/recommendations', { date_range: range }).then(function(res) {
        const insights = res?.data || res || [];
        renderAiInsights(insights);
    }).fail(function() {
        $('#ai-insights-list').html('<div class="text-muted text-center py-3" style="font-size:13px;">No AI insights available</div>');
    });

    // ── KPIs ──
    function renderKpis(ov, sess, rv) {
        // ov.traffic = overview traffic summary, sess.metrics = session metrics, rv.daily = revenue breakdown
        const traffic = ov.traffic || {};
        const metrics = sess.metrics || {};
        const daily = rv.daily || {};

        const totalVisits = metrics.total_sessions || traffic.unique_sessions || 0;
        const uniqueVisitors = traffic.unique_sessions || metrics.total_sessions || 0;
        const pageviews = traffic.total_events || metrics.total_sessions || 0;
        const bounce = metrics.bounce_rate || 0;
        const avgDuration = metrics.avg_session_duration_seconds || 0;
        const revenue = daily.total_revenue || (rv.comparison || {}).current?.revenue || 0;

        animateValue('kpi-visits', totalVisits);
        animateValue('kpi-unique', uniqueVisitors);
        animateValue('kpi-pageviews', pageviews);
        $('#kpi-bounce').text((bounce || 0).toFixed(1) + '%');
        $('#kpi-duration').text(formatDuration(avgDuration));
        $('#kpi-revenue').text('$' + fmt.number(revenue));
    }

    // ── Visits Chart (line) ──
    function renderVisitsChart(sess) {
        // sessions API returns daily_trend: { dates: [...], sessions: [...] }
        const trend = sess.daily_trend || {};
        const dates = trend.dates || [];
        const sessArr = trend.sessions || [];
        if (!dates.length) { $('#visits-chart').html('<div class="e360-empty-state" style="padding:60px 0;"><div class="empty-icon">📊</div><p>No visit data for this period</p></div>'); return; }

        const cats = dates;
        const visits = sessArr;
        const pv = sessArr.map(v => v); // use sessions as proxy for pageviews

        window._visitsChart = new ApexCharts(document.querySelector('#visits-chart'), {
            ...chartDefaults,
            chart: { ...chartDefaults.chart, type: 'area', height: 320 },
            series: [
                { name: 'Visits', data: visits },
                { name: 'Pageviews', data: pv },
            ],
            xaxis: { categories: cats, labels: { style: { fontSize: '11px' } }, tickAmount: Math.min(dates.length, 15) },
            yaxis: { labels: { formatter: v => fmt.number(Math.round(v)) } },
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 } },
            dataLabels: { enabled: false },
        });
        window._visitsChart.render();
    }

    // ── Sources Donut ──
    function renderSourcesChart(camp, ov, sess) {
        // Prefer referrer_sources (has multiple sources with sessions) over channel_attribution (may have just "direct")
        const refSources = camp.referrer_sources || [];
        const ca = camp.channel_attribution || {};
        let channels = ca.channels || [];

        // Use referrer sources if available (more granular), otherwise fall back to channels
        let labels, values;
        if (refSources.length > 1) {
            // Group small referrers into "Other"
            const top = refSources.slice(0, 7);
            const otherSessions = refSources.slice(7).reduce((s,r) => s + (r.sessions||0), 0);
            labels = top.map(r => { try { return new URL(r.referrer).hostname.replace('www.',''); } catch(e) { return r.referrer || 'Direct'; } });
            values = top.map(r => r.sessions || 0);
            if (otherSessions > 0) { labels.push('Other'); values.push(otherSessions); }
            // Add direct traffic (sessions not from referrers)
            const totalRef = values.reduce((a,b)=>a+b,0);
            const totalSessions = (sess?.metrics?.total_sessions || ov?.traffic?.unique_sessions || 0);
            const directSessions = Math.max(0, totalSessions - totalRef);
            if (directSessions > 0) { labels.unshift('Direct'); values.unshift(directSessions); }
        } else if (channels.length) {
            labels = channels.map(c => c.channel || c.name || 'Unknown');
            values = channels.map(c => c.conversions || c.sessions || c.revenue || 0);
        } else {
            $('#sources-chart').html('<div class="text-muted text-center py-4">No source data</div>'); return;
        }

        if (!values.some(v => v > 0)) { $('#sources-chart').html('<div class="text-muted text-center py-4">No source data</div>'); return; }

        new ApexCharts(document.querySelector('#sources-chart'), {
            ...chartDefaults,
            chart: { ...chartDefaults.chart, type: 'donut', height: 280 },
            series: values,
            labels: labels,
            legend: { position: 'bottom', fontSize: '11px' },
            plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', formatter: w => fmt.number(w.globals.seriesTotals.reduce((a,b)=>a+b,0)) } } } } },
        }).render();
    }

    // ── Devices Donut ──
    function renderDevicesChart(geo) {
        const devices = geo.device_breakdown || geo.devices || [];
        if (!devices.length) { $('#devices-chart').html('<div class="text-muted text-center py-4">No device data</div>'); return; }

        const labels = devices.map(d => d.device || d.type || d.name);
        const values = devices.map(d => d.count || d.visits || d.sessions || 0);

        new ApexCharts(document.querySelector('#devices-chart'), {
            ...chartDefaults,
            chart: { ...chartDefaults.chart, type: 'donut', height: 240 },
            series: values,
            labels: labels,
            colors: ['#1A56DB', '#10B981', '#F59E0B'],
            legend: { position: 'bottom', fontSize: '11px' },
            plotOptions: { pie: { donut: { size: '60%' } } },
        }).render();
    }

    // ── Pages Table ──
    function renderPages(pgs) {
        // all-pages API: pages[] with { pageviews, url, unique, avg_time, bounce_rate, exit_rate }
        const pages = (pgs.pages || []).slice(0, 8);
        if (!pages.length) { $('#pages-mini-body').html('<tr><td colspan="3" class="text-muted text-center py-3">No page data</td></tr>'); return; }

        let html = '';
        pages.forEach(p => {
            const url = p.url || p.page || p.path || '(not set)';
            const views = p.pageviews || p.count || p.visits || p.views || 0;
            const time = p.avg_time || p.duration || 0;
            html += `<tr>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="${url}">${url}</td>
                <td class="text-end mono" style="font-size:12px;">${fmt.number(views)}</td>
                <td class="text-end mono" style="font-size:12px;">${formatDuration(time)}</td>
            </tr>`;
        });
        $('#pages-mini-body').html(html);
    }

    // ── Referrers Table ──
    function renderReferrers(camp) {
        // campaigns API: referrer_sources[] with referrer, sessions
        const refSources = (camp.referrer_sources || []).slice(0, 8);
        if (!refSources.length) { $('#referrers-mini-body').html('<tr><td colspan="3" class="text-muted text-center py-3">No referrer data</td></tr>'); return; }

        let html = '';
        refSources.forEach(r => {
            const src = r.source || r.referrer || r.name || 'Direct';
            const visits = r.visits || r.sessions || r.count || 0;
            const conv = r.conversion_rate || r.conv || null;
            html += `<tr>
                <td style="font-size:12px;">${src}</td>
                <td class="text-end mono" style="font-size:12px;">${fmt.number(visits)}</td>
                <td class="text-end mono" style="font-size:12px;">${conv !== null ? conv.toFixed(1)+'%' : '—'}</td>
            </tr>`;
        });
        $('#referrers-mini-body').html(html);
    }

    // ── Ecommerce Summary ──
    function renderEcommerce(rv) {
        // revenue API: { daily: { dates, revenues, orders, aov, total_revenue, total_orders, average_order_value } }
        const daily = rv.daily || {};
        const orders = daily.total_orders || 0;
        const revenue = daily.total_revenue || 0;
        const aov = daily.average_order_value || (orders > 0 ? revenue / orders : 0);

        animateValue('ecom-orders', orders);
        $('#ecom-revenue').text('$' + fmt.number(revenue));
        $('#ecom-aov').text('$' + aov.toFixed(2));

        const dates = daily.dates || [];
        const revenues = daily.revenues || [];
        if (!dates.length) return;

        new ApexCharts(document.querySelector('#revenue-sparkline'), {
            ...chartDefaults,
            chart: { ...chartDefaults.chart, type: 'area', height: 160, sparkline: { enabled: false } },
            series: [{ name: 'Revenue', data: revenues }],
            xaxis: { categories: dates, labels: { show: true, style: { fontSize: '10px' } }, tickAmount: Math.min(dates.length, 10) },
            yaxis: { labels: { formatter: v => '$' + fmt.number(Math.round(v)), style: { fontSize: '10px' } } },
            colors: ['#10B981'],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
            dataLabels: { enabled: false },
        }).render();
    }

    // ── Funnel ──
    function renderFunnel(fn) {
        const stages = fn.stages || fn.funnel || [];
        if (!stages.length) { $('#funnel-chart').html('<div class="text-muted text-center py-4">No funnel data</div>'); return; }

        const labels = stages.map(s => s.stage || s.name || s.label);
        const values = stages.map(s => s.unique_sessions || s.count || s.visitors || s.value || 0);

        new ApexCharts(document.querySelector('#funnel-chart'), {
            ...chartDefaults,
            chart: { ...chartDefaults.chart, type: 'bar', height: 260 },
            series: [{ name: 'Visitors', data: values }],
            plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '60%', distributed: true } },
            xaxis: { categories: labels },
            yaxis: { labels: { style: { fontSize: '12px', fontWeight: 600 } } },
            colors: ['#1A56DB', '#0891B2', '#F59E0B', '#10B981'],
            legend: { show: false },
            dataLabels: { enabled: true, formatter: v => fmt.number(v), style: { fontSize: '12px', fontWeight: 600 } },
        }).render();
    }

    // ── Countries ──
    function renderCountries(geo) {
        const countries = (geo.by_country || geo.countries || []).slice(0, 10);
        if (!countries.length) { $('#countries-list').html('<div class="text-muted text-center py-3">No geographic data</div>'); return; }

        const maxVal = Math.max(...countries.map(c => c.sessions || c.count || c.visits || 0));
        let html = '';
        countries.forEach(c => {
            const name = c.country || c.name;
            const count = c.sessions || c.count || c.visits || 0;
            const pct = maxVal > 0 ? (count / maxVal * 100) : 0;
            html += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <span style="font-size:12px;min-width:100px;color:var(--neutral-700);">${name}</span>
                <div style="flex:1;height:6px;background:var(--neutral-100);border-radius:3px;overflow:hidden;">
                    <div style="width:${pct}%;height:100%;background:var(--primary-500);border-radius:3px;"></div>
                </div>
                <span class="mono" style="font-size:11px;color:var(--neutral-500);min-width:40px;text-align:right;">${fmt.number(count)}</span>
            </div>`;
        });
        $('#countries-list').html(html);
    }

    // ── Real-time mini ──
    function renderRealtime(rt, geo) {
        const count = rt.active_sessions_5min || rt.active_sessions || rt.active_visitors || 0;
        const epm = rt.events_per_minute || 0;
        const countries = (rt.geo_breakdown || rt.countries || []).length || (geo && geo.by_country ? geo.by_country.length : 0);
        animateValue('rt-count', count);
        $('#rt-epm').text(epm);
        $('#rt-countries').text(countries);
    }

    // ── AI Insights ──
    function renderAiInsights(data) {
        let items = [];
        if (Array.isArray(data)) items = data;
        else if (data.recommendations) items = data.recommendations;
        else if (data.insights) items = data.insights;

        if (!items.length) { $('#ai-insights-list').html('<div class="text-muted text-center py-3" style="font-size:13px;">No AI insights available yet</div>'); return; }

        let html = '';
        items.slice(0, 5).forEach((item, i) => {
            const text = typeof item === 'string' ? item : (item.recommendation || item.text || item.title || item.message || '');
            const type = item.type || item.category || 'info';
            const color = type === 'warning' ? 'var(--warning)' : type === 'danger' ? 'var(--danger)' : type === 'success' ? 'var(--success)' : 'var(--primary-500)';
            html += `<div style="display:flex;gap:10px;padding:8px 0;${i > 0 ? 'border-top:1px solid var(--border);' : ''}">
                <div style="min-width:6px;height:6px;margin-top:7px;border-radius:50%;background:${color};"></div>
                <div style="font-size:12.5px;color:var(--neutral-700);line-height:1.5;">${text}</div>
            </div>`;
        });
        $('#ai-insights-list').html(html);
    }

    // ── Helpers ──
    function animateValue(id, endVal) {
        const el = document.getElementById(id);
        if (!el) return;
        if (endVal === 0) { el.textContent = '0'; return; }
        let start = 0;
        const duration = 800;
        const step = (timestamp) => {
            if (!start) start = timestamp;
            const progress = Math.min((timestamp - start) / duration, 1);
            el.textContent = EcomUtils.number(Math.round(progress * endVal));
            if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    }

    function formatDuration(seconds) {
        if (!seconds || seconds < 1) return '0s';
        const m = Math.floor(seconds / 60);
        const s = Math.round(seconds % 60);
        return m > 0 ? m + 'm ' + s + 's' : s + 's';
    }

    // ── Real-time auto-refresh (every 30s) ──
    setInterval(function() {
        $.get(API + '/realtime').then(function(res) {
            const rt = res?.data || res || {};
            renderRealtime(rt, _geoData);
        });
    }, 30000);

})();
</script>
@endsection
