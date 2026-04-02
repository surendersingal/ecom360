@extends('layouts.tenant')
@section('title', 'Events')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-pulse" style="color:var(--analytics);"></i> Events</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Events</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Events</span></div><div class="kpi-value" id="ev-total">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Event Categories</span></div><div class="kpi-value" id="ev-cats">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Actions</span></div><div class="kpi-value" id="ev-actions">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Events / Visit</span></div><div class="kpi-value" id="ev-per">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Events Over Time</h5>
                    <div id="ev-trend-chart" style="height:280px;"></div>
                </div></div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Event Categories</h5>
                    <div id="ev-cat-chart" style="height:280px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Event Breakdown</h5>
                        <select id="ev-filter" class="form-select form-select-sm" style="width:180px;"><option value="">All Categories</option></select>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Category</th><th>Action</th><th>Label</th><th class="text-end">Count</th><th class="text-end">Unique</th></tr></thead>
                            <tbody id="ev-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
@endsection
@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection
@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    const range = new URLSearchParams(location.search).get('date_range') || '30d';
    let allEvents = [];

    // Fetch real event breakdown from backend
    $.get(API + '/events-breakdown', { date_range: range }).then(function(res) {
        const data = res?.data || res || {};
        const events = data.breakdown || [];
        const categories = data.categories || [];
        const totalEvents = data.total_events || events.reduce((s,e) => s + (e.count || 0), 0);

        $('#ev-total').text(EcomUtils.number(totalEvents));
        const cats = categories.map(c => c.category);
        $('#ev-cats').text(cats.length);
        const actions = [...new Set(events.map(e => e.action || ''))];
        $('#ev-actions').text(actions.length);

        // Events per visit from sessions API
        $.get(API + '/sessions', { date_range: range }).then(function(sRes) {
            const s = sRes?.data || sRes || {};
            const metrics = s.metrics || {};
            const totalVisits = metrics.total_sessions || s.total_sessions || 1;
            $('#ev-per').text((totalEvents / totalVisits).toFixed(1));
        }).catch(() => $('#ev-per').text('—'));

        // Category filter
        cats.forEach(c => { $('#ev-filter').append(`<option>${c}</option>`); });

        allEvents = events;
        renderTable(events);

        // Filter handler
        $('#ev-filter').on('change', function() {
            const f = this.value;
            renderTable(f ? allEvents.filter(e => (e.category||'') === f) : allEvents);
        });

        // Category pie from real categories data
        if (categories.length) {
            new ApexCharts(document.querySelector('#ev-cat-chart'), {
                chart:{type:'donut',height:280,fontFamily:'Inter'},
                series:categories.map(c=>c.count||0),
                labels:categories.map(c=>c.category),
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }

        // Trend - fetch sessions for daily (parallel arrays)
        $.get(API + '/sessions', { date_range: range }).then(function(tRes) {
            const t = tRes?.data || tRes || {};
            const trend = t.daily_trend || {};
            const dates = trend.dates || [];
            const sessArr = trend.sessions || [];
            if (dates.length) {
                new ApexCharts(document.querySelector('#ev-trend-chart'), {
                    chart:{type:'bar',height:280,fontFamily:'Inter',toolbar:{show:false}},
                    series:[{name:'Sessions',data:sessArr}],
                    xaxis:{categories:dates,tickAmount:Math.min(dates.length,12)},
                    colors:['#1A56DB'],
                    plotOptions:{bar:{borderRadius:4,columnWidth:'60%'}},
                    dataLabels:{enabled:false},
                }).render();
            }
        });
    }).catch(function(){
        console.warn('Failed to load events data');
        $('#ev-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });

    function renderTable(events) {
        if (!events.length) { $('#ev-body').html('<tr><td colspan="5" class="text-center text-muted py-3">No events recorded</td></tr>'); return; }
        let h = '';
        events.forEach(e => {
            h += `<tr>
                <td style="font-size:12px;"><span class="badge bg-light text-dark">${e.category||e.event_category||'—'}</span></td>
                <td style="font-size:12px;">${e.action||e.event_action||e.name||'—'}</td>
                <td style="font-size:12px;">${e.label||e.event_label||'—'}</td>
                <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(e.count||e.total||0)}</td>
                <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(e.unique || 0)}</td>
            </tr>`;
        });
        $('#ev-body').html(h);
    }
})();
</script>
@endsection
