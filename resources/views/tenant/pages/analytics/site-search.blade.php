@extends('layouts.tenant')
@section('title', 'Site Search')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-search-alt-2" style="color:var(--analytics);"></i> Site Search</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Site Search</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Searches</span></div><div class="kpi-value" id="ss-total">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Keywords</span></div><div class="kpi-value" id="ss-unique">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">No-Result Rate</span></div><div class="kpi-value" id="ss-pct">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">No-Result Searches</span></div><div class="kpi-value" id="ss-noresult">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Search Keywords</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Keyword</th><th class="text-end">Searches</th><th class="text-end">Results Avg.</th><th class="text-end">Unique</th></tr></thead>
                            <tbody id="ss-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Search Volume</h5>
                    <div id="ss-bar-chart" style="height:320px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">No-Result Keywords</h5>
                    <p class="text-muted" style="font-size:12px;">Searches that returned zero results — content gap opportunities</p>
                    <div id="ss-noresult-list" class="d-flex flex-wrap gap-2"><span class="text-muted">Loading...</span></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Search Trend</h5>
                    <div id="ss-trend-chart" style="height:260px;"></div>
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

    // Search data from dedicated search-analytics endpoint
    $.get(API + '/search-analytics', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const searches = d.keywords || [];
        const totalSearches = d.total_searches || searches.reduce((s,k) => s + (k.searches || 0), 0);

        $('#ss-total').text(EcomUtils.number(totalSearches));
        $('#ss-unique').text(d.unique_keywords || searches.length);
        // Show no-result rate (the rate of searches returning zero results)
        $('#ss-pct').text(d.no_result_rate != null ? d.no_result_rate + '%' : '—');

        const noResults = d.no_result_searches || 0;
        $('#ss-noresult').text(noResults);

        if (searches.length) {
            let h = '';
            searches.slice(0, 30).forEach((k, i) => {
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;font-weight:500;">${k.keyword || k.query || '—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(k.searches || 0)}</td>
                    <td class="text-end mono" style="font-size:12px;">${k.avg_results != null ? k.avg_results : '—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(k.unique || 0)}</td>
                </tr>`;
            });
            $('#ss-body').html(h);

            // Bar chart top 10
            const top = searches.slice(0, 10);
            new ApexCharts(document.querySelector('#ss-bar-chart'), {
                chart:{type:'bar',height:320,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Searches',data:top.map(k=>k.searches||0)}],
                xaxis:{categories:top.map(k=>k.keyword||k.query||'?')},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#6366f1'],
                dataLabels:{enabled:false},
            }).render();
        }

        // No-result keywords (filter from response where avg_results === 0)
        const noResultKeywords = searches.filter(k => (k.avg_results || 0) === 0);
        if (noResultKeywords.length) {
            $('#ss-noresult-list').html(noResultKeywords.map(k =>
                `<span class="badge bg-danger bg-opacity-10 text-danger" style="font-size:11px;">${k.keyword||k.query}</span>`
            ).join(''));
        } else {
            $('#ss-noresult-list').html('<span class="text-success">All searches returned results!</span>');
        }
    }).catch(function() {
        $('#ss-body').html('<tr><td colspan="5" class="text-muted text-center py-3">No search data available</td></tr>');
        $('#ss-noresult-list').html('<span class="text-muted">No data</span>');
    });

    // Trend chart — use sessions daily_trend
    $.get(API + '/sessions', { date_range: range }).then(function(tRes) {
        const t = tRes?.data || tRes || {};
        const trend = t.daily_trend || {};
        const dates = trend.dates || [];
        const sessArr = trend.sessions || [];
        if (dates.length) {
            new ApexCharts(document.querySelector('#ss-trend-chart'), {
                chart:{type:'area',height:260,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Sessions',data:sessArr}],
                xaxis:{categories:dates,tickAmount:Math.min(dates.length,10)},
                colors:['#6366f1'],
                fill:{type:'gradient',gradient:{opacityFrom:0.3,opacityTo:0.05}},
                stroke:{curve:'smooth',width:2},
                dataLabels:{enabled:false},
            }).render();
        }
    });
})();
</script>
@endsection
