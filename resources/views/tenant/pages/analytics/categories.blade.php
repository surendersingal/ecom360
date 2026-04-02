@extends('layouts.tenant')
@section('title', 'Category Analytics')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-category" style="color:var(--analytics);"></i> Categories</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Categories</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active Categories</span></div><div class="kpi-value" id="cat-count">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Views</span></div><div class="kpi-value" id="cat-rev">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Top Category</span></div><div class="kpi-value" id="cat-top" style="font-size:14px;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. Views / Category</span></div><div class="kpi-value" id="cat-avg">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Views by Category</h5>
                    <div id="cat-pie-chart" style="height:320px;"></div>
                </div></div>
            </div>
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Category Performance</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Category</th><th class="text-end">Views</th><th class="text-end">Unique Visitors</th><th class="text-end">Avg. Views/Visitor</th></tr></thead>
                            <tbody id="cat-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Category Views Comparison</h5>
                    <div id="cat-bar-chart" style="height:300px;"></div>
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

    // Use /categories endpoint — returns { category_views: [{_id, views, category, unique_visitors}], category_purchases: [] }
    $.get(API + '/categories', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const cats = d.category_views || d.categories || [];
        const totalViews = cats.reduce((s,c)=>s+(c.views||0),0);

        $('#cat-count').text(cats.length);
        $('#cat-rev').text(EcomUtils.number(totalViews) + ' views');
        if (cats.length) $('#cat-top').text(cats[0].category || cats[0].name || cats[0]._id || '—');
        $('#cat-avg').text(cats.length ? EcomUtils.number(Math.round(totalViews/cats.length)) + ' avg' : '—');

        if (cats.length) {
            // Table
            let h = '';
            cats.forEach((c, i) => {
                const views = c.views || 0;
                const uv = c.unique_visitors || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;font-weight:500;">${c.category||c.name||c._id||'—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(views)}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(uv)}</td>
                    <td class="text-end mono" style="font-size:12px;">${uv ? (views/uv).toFixed(1) : '—'}</td>
                </tr>`;
            });
            $('#cat-body').html(h);

            // Pie
            new ApexCharts(document.querySelector('#cat-pie-chart'), {
                chart:{type:'donut',height:320,fontFamily:'Inter'},
                series:cats.slice(0,8).map(c=>c.views||0),
                labels:cats.slice(0,8).map(c=>c.category||c.name||c._id||'?'),
                legend:{position:'bottom',fontSize:'10px'},
            }).render();

            // Bar comparison
            new ApexCharts(document.querySelector('#cat-bar-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[
                    {name:'Views',data:cats.slice(0,10).map(c=>c.views||0)},
                    {name:'Unique Visitors',data:cats.slice(0,10).map(c=>c.unique_visitors||0)}
                ],
                xaxis:{categories:cats.slice(0,10).map(c=>(c.category||c.name||c._id||'?').substring(0,18))},
                plotOptions:{bar:{borderRadius:4,columnWidth:'55%'}},
                colors:['#059669','#1A56DB'],
                dataLabels:{enabled:false},
                legend:{position:'top'},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load categories data');
        $('#cat-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
