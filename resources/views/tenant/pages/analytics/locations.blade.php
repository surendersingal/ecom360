@extends('layouts.tenant')
@section('title', 'Locations')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-map" style="color:var(--analytics);"></i> Locations</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Locations</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Visitors by Country</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0"><thead><tr><th>#</th><th>Country</th><th>Visits</th><th>Cities</th><th style="width:200px;">Share</th></tr></thead>
                        <tbody id="country-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody></table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;"><div class="card-body">
                    <h5 class="card-title">Country Distribution</h5>
                    <div id="country-chart" style="height:350px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Cities</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0"><thead><tr><th>#</th><th>City</th><th>Country</th><th class="text-end">Visits</th><th class="text-end">%</th></tr></thead>
                        <tbody id="city-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody></table>
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

    $.get(API + '/geographic', { date_range: range }).then(function(res) {
        const g = res?.data || res || {};
        const countries = g.by_country || g.countries || [];
        const cities = g.by_city || g.cities || [];
        const total = countries.reduce((s,c) => s + (c.sessions||c.count||c.visits||0), 0) || 1;

        if (countries.length) {
            let h = '';
            countries.forEach((c, i) => {
                const v = c.sessions||c.count||c.visits||0;
                const pct = (v/total*100);
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:13px;font-weight:500;">${c.country||c.name}</td>
                    <td class="mono" style="font-size:13px;">${EcomUtils.number(v)}</td>
                    <td style="font-size:13px;">${c.unique_cities || '—'}</td>
                    <td><div style="display:flex;align-items:center;gap:8px;">
                        <div style="flex:1;height:6px;background:var(--neutral-100);border-radius:3px;overflow:hidden;">
                            <div style="width:${pct}%;height:100%;background:var(--primary-500);border-radius:3px;"></div>
                        </div>
                        <span class="mono" style="font-size:11px;min-width:40px;text-align:right;">${pct.toFixed(1)}%</span>
                    </div></td>
                </tr>`;
            });
            $('#country-body').html(h);

            new ApexCharts(document.querySelector('#country-chart'), {
                chart:{type:'donut',height:350,fontFamily:'Inter'},
                series: countries.slice(0,8).map(c=>c.sessions||c.count||c.visits||0),
                labels: countries.slice(0,8).map(c=>c.country||c.name),
                legend:{position:'bottom',fontSize:'11px'},
                plotOptions:{pie:{donut:{size:'55%'}}},
            }).render();
        }

        if (cities.length) {
            const cityTotal = cities.reduce((s,c) => s + (c.sessions||c.count||c.visits||0), 0) || 1;
            let h = '';
            cities.slice(0,20).forEach((c, i) => {
                const v = c.sessions||c.count||c.visits||0;
                h += `<tr><td class="text-muted" style="font-size:11px;">${i+1}</td><td style="font-size:13px;">${c.city||c.name}</td><td style="font-size:13px;">${c.country||'—'}</td><td class="text-end mono" style="font-size:13px;">${EcomUtils.number(v)}</td><td class="text-end mono" style="font-size:13px;">${(v/cityTotal*100).toFixed(1)}%</td></tr>`;
            });
            $('#city-body').html(h);
        } else $('#city-body').html('<tr><td colspan="5" class="text-muted text-center py-3">No city data</td></tr>');
    }).catch(function(){
        console.warn('Failed to load locations data');
        $('#country-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
        $('#city-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
