@extends('layouts.tenant')
@section('title', 'Real-Time Visitors')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-pulse" style="color:var(--danger);"></i> Real-Time</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Real-Time</li>
            </ol></nav>
        </div>
        <div class="header-actions">
            <span class="e360-live-badge"><span class="live-dot"></span> Live — refreshes every 10s</span>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card" style="background:linear-gradient(135deg,var(--neutral-900),#1e293b);color:#fff;">
                    <div class="kpi-header"><span class="kpi-label" style="color:rgba(255,255,255,0.7);">Active Visitors</span></div>
                    <div class="kpi-value" id="rt-active" style="color:#fff;font-size:48px;">{{ $rt['active_sessions_5min'] ?? $rt['active_sessions'] ?? 0 }}</div>
                    <div class="kpi-sub" style="color:rgba(255,255,255,0.5);">right now on site</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Events / Min</span><span class="kpi-icon orders"><i class="bx bx-pulse"></i></span></div>
                <div class="kpi-value" id="rt-epm">{{ $rt['events_per_minute'] ?? 0 }}</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active Pages</span><span class="kpi-icon revenue"><i class="bx bx-file"></i></span></div>
                <div class="kpi-value" id="rt-pages">0</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Countries</span><span class="kpi-icon conversion"><i class="bx bx-globe"></i></span></div>
                <div class="kpi-value" id="rt-countries">0</div></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Active Pages</h5>
                    <div class="table-responsive"><table class="table table-sm mb-0">
                        <thead><tr><th>Page URL</th><th class="text-end">Active Users</th></tr></thead>
                        <tbody id="rt-pages-body">
                            <tr><td colspan="2" class="text-muted text-center py-3">Loading...</td></tr>
                        </tbody>
                    </table></div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;"><div class="card-body">
                    <h5 class="card-title">Live Event Stream</h5>
                    <div id="rt-event-stream" style="max-height:400px;overflow-y:auto;">
                        <div class="text-muted text-center py-3">Waiting for events...</div>
                    </div>
                </div></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Visitors by Country (Live)</h5>
                    <div class="row" id="rt-geo-list">
                        <div class="col-12 text-muted text-center py-3">Loading...</div>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
@endsection
@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';

    function refresh() {
        $.get(API + '/realtime').then(function(res) {
            const rt = res?.data || res || {};
            $('#rt-active').text(rt.active_sessions_5min || rt.active_sessions || 0);
            $('#rt-epm').text(rt.events_per_minute || 0);

            // Derive active pages and countries from recent-events
            $.get(API + '/recent-events', { limit: 50 }).then(function(evRes) {
                const events = (evRes?.data || evRes || {}).events || [];

                // Active pages — group page_view events by URL
                const pageMap = {};
                const countryMap = {};
                events.forEach(e => {
                    if (e.metadata?.url) {
                        const url = e.metadata.url;
                        pageMap[url] = (pageMap[url]||0) + 1;
                    }
                    const geoCountry = e.metadata?.geo?.country || e.metadata?.country || e.custom_data?.country;
                    if (geoCountry && geoCountry !== 'Unknown' && geoCountry !== 'Local') {
                        countryMap[geoCountry] = (countryMap[geoCountry]||0) + 1;
                    }
                });

                const pages = Object.entries(pageMap).map(([url,count])=>({url,count})).sort((a,b)=>b.count-a.count);
                const countries = Object.entries(countryMap).map(([country,count])=>({country,count})).sort((a,b)=>b.count-a.count);

                $('#rt-pages').text(pages.length);
                $('#rt-countries').text(countries.length);

                // Pages table
                if (pages.length) {
                    let h = '';
                    pages.forEach(p => {
                        h += `<tr><td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;">${p.url}</td><td class="text-end mono" style="font-weight:600;color:var(--primary-500);">${p.count}</td></tr>`;
                    });
                    $('#rt-pages-body').html(h);
                }

                // Geo
                if (countries.length) {
                    let h = '';
                    countries.forEach(g => {
                        h += `<div class="col-xl-2 col-md-3 col-4 mb-2"><div style="font-size:13px;font-weight:500;">${g.country}</div><div class="mono" style="font-size:18px;font-weight:700;color:var(--primary-500);">${g.count}</div></div>`;
                    });
                    $('#rt-geo-list').html(h);
                }

                // Live event stream
                if (events.length) {
                    let eh = '';
                    events.forEach(e => {
                        const type = e.event_type || 'unknown';
                        const color = type === 'purchase' ? 'var(--success)' : type === 'add_to_cart' ? 'var(--warning)' : type === 'product_view' ? '#0891B2' : 'var(--primary-500)';
                        const page = e.metadata?.url || e.metadata?.product_name || e.metadata?.query || '/';
                        const time = e.created_at ? new Date(e.created_at).toLocaleTimeString() : '';
                        eh += `<div style="display:flex;gap:8px;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);">
                            <span style="font-size:10px;color:var(--neutral-400);min-width:60px;">${time}</span>
                            <span class="badge" style="background:${color};font-size:9px;">${type}</span>
                            <span style="font-size:11px;color:var(--neutral-500);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${page}</span>
                        </div>`;
                    });
                    $('#rt-event-stream').html(eh);
                }
            }).catch(function(){});
        });
    }

    refresh();
    setInterval(refresh, 10000);
})();
</script>
@endsection
