@extends('layouts.tenant')
@section('title', 'Pages')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-file" style="color:var(--analytics);"></i> Pages</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Pages</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Pageviews</span></div><div class="kpi-value" id="pg-total">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Pages</span></div><div class="kpi-value" id="pg-unique">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. Time on Page</span></div><div class="kpi-value" id="pg-time">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Bounce Rate</span></div><div class="kpi-value" id="pg-bounce">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">All Pages</h5>
                        <input type="text" class="form-control form-control-sm" id="page-search" placeholder="Filter pages..." style="width:220px;">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" id="pages-table">
                            <thead><tr><th>#</th><th>Page URL</th><th class="text-end">Pageviews</th><th class="text-end">Unique</th><th class="text-end">Avg. Time</th><th class="text-end">Bounce Rate</th><th class="text-end">Exit Rate</th></tr></thead>
                            <tbody id="pages-body"><tr><td colspan="7" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Pageviews Trend</h5>
                    <div id="pv-trend-chart" style="height:280px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Pages Distribution</h5>
                    <div id="pv-pie-chart" style="height:280px;"></div>
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

    $.get(API + '/all-pages', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const pages = d.pages || [];
        const total = pages.reduce((s,p) => s + (p.pageviews || p.views || p.count || 0), 0);

        $('#pg-total').text(EcomUtils.number(total));
        $('#pg-unique').text(pages.length);
        const avgTime = pages.length ? Math.round(pages.reduce((s,p) => s + (p.avg_time||0), 0) / pages.length) : 0;
        $('#pg-time').text(avgTime ? avgTime+'s' : '—');
        const avgBounce = pages.length ? (pages.reduce((s,p) => s + (p.bounce_rate||0), 0) / pages.length) : 0;
        $('#pg-bounce').text(avgBounce ? avgBounce.toFixed(1)+'%' : '—');

        if (pages.length) {
            let h = '';
            pages.forEach((p, i) => {
                const url = p.url || p.page || p.path || '/';
                const views = p.pageviews || p.views || p.count || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${url}">${url}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(views)}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(p.unique || 0)}</td>
                    <td class="text-end mono" style="font-size:12px;">${p.avg_time ? Math.round(p.avg_time)+'s' : '—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${p.bounce_rate ? p.bounce_rate.toFixed(1)+'%' : '—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${p.exit_rate ? p.exit_rate.toFixed(1)+'%' : '—'}</td>
                </tr>`;
            });
            $('#pages-body').html(h);
        }

        // Search filter
        $('#page-search').on('input', function() {
            const q = this.value.toLowerCase();
            $('#pages-body tr').each(function() {
                $(this).toggle($(this).text().toLowerCase().includes(q));
            });
        });

        // Trend chart from sessions API (parallel arrays)
        $.get(API + '/sessions', { date_range: range }).then(function(tRes) {
            const t = tRes?.data || tRes || {};
            const trend = t.daily_trend || {};
            const dates = trend.dates || [];
            const sessArr = trend.sessions || [];
            if (dates.length) {
                new ApexCharts(document.querySelector('#pv-trend-chart'), {
                    chart:{type:'area',height:280,fontFamily:'Inter',toolbar:{show:false}},
                    series:[{name:'Sessions',data:sessArr}],
                    xaxis:{categories:dates,tickAmount:Math.min(dates.length,12)},
                    colors:['#1A56DB'],
                    fill:{type:'gradient',gradient:{opacityFrom:0.3,opacityTo:0.05}},
                    stroke:{curve:'smooth',width:2},
                    dataLabels:{enabled:false},
                }).render();
            }
        });

        // Pie chart
        if (pages.length) {
            const top = pages.slice(0,8);
            new ApexCharts(document.querySelector('#pv-pie-chart'), {
                chart:{type:'donut',height:280,fontFamily:'Inter'},
                series:top.map(p=>p.pageviews||p.views||p.count||0),
                labels:top.map(p=>(p.url||p.page||'/').replace(/https?:\/\/[^/]+/,'')),
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load pages data');
        $('#pages-body').html('<tr><td colspan="7" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
