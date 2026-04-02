@extends('layouts.tenant')
@section('title', 'Devices')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-devices" style="color:var(--analytics);"></i> Devices</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Devices</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;"><div class="card-body">
                    <h5 class="card-title">Device Type</h5>
                    <div id="device-type-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;"><div class="card-body">
                    <h5 class="card-title">Browsers</h5>
                    <div id="browser-chart" style="height:300px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Operating Systems</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0"><thead><tr><th>OS</th><th class="text-end">Visits</th><th class="text-end">%</th></tr></thead>
                        <tbody id="os-body"><tr><td colspan="3" class="text-center py-3"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody></table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Screen Resolutions</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0"><thead><tr><th>Resolution</th><th class="text-end">Visits</th><th class="text-end">%</th></tr></thead>
                        <tbody id="resolution-body"><tr><td colspan="3" class="text-center py-3"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody></table>
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
        const devices = g.device_breakdown || g.devices || [];
        const browsers = g.browser_breakdown || g.browsers || [];
        const oses = g.os_breakdown || g.operating_systems || [];
        const resolutions = g.resolution_breakdown || g.resolutions || [];
        const deviceTotal = devices.reduce((s,d) => s + (d.count||d.visits||0), 0) || 1;
        const osTotal = oses.reduce((s,o) => s + (o.count||o.visits||0), 0) || 1;
        const resTotal = resolutions.reduce((s,r) => s + (r.count||r.visits||0), 0) || 1;

        // Device donut
        if (devices.length) {
            new ApexCharts(document.querySelector('#device-type-chart'), {
                chart:{type:'donut',height:300,fontFamily:'Inter'},
                series: devices.map(d=>d.count||d.visits||0),
                labels: devices.map(d=>d.device||d.type||d.name),
                colors:['#1A56DB','#10B981','#F59E0B','#EF4444'],
                legend:{position:'bottom'},
                plotOptions:{pie:{donut:{size:'60%',labels:{show:true,total:{show:true}}}}},
            }).render();
        }

        // Browser bar
        if (browsers.length) {
            new ApexCharts(document.querySelector('#browser-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:browsers.slice(0,10).map(b=>b.count||b.visits||0)}],
                xaxis:{categories:browsers.slice(0,10).map(b=>b.browser||b.name)},
                colors:['#0891B2'],
                plotOptions:{bar:{borderRadius:6,horizontal:true}},
                dataLabels:{enabled:true,formatter:v=>EcomUtils.number(v),style:{fontSize:'11px'}},
            }).render();
        }

        // OS table
        if (oses.length) {
            let h = '';
            oses.slice(0,10).forEach(o => {
                const v = o.count||o.visits||0;
                h += `<tr><td style="font-size:13px;">${o.os||o.name}</td><td class="text-end mono" style="font-size:13px;">${EcomUtils.number(v)}</td><td class="text-end mono" style="font-size:13px;">${(v/osTotal*100).toFixed(1)}%</td></tr>`;
            });
            $('#os-body').html(h);
        } else $('#os-body').html('<tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr>');

        // Resolutions table
        if (resolutions.length) {
            let h = '';
            resolutions.slice(0,10).forEach(r => {
                const v = r.count||r.visits||0;
                h += `<tr><td style="font-size:13px;">${r.resolution||r.name}</td><td class="text-end mono" style="font-size:13px;">${EcomUtils.number(v)}</td><td class="text-end mono" style="font-size:13px;">${(v/resTotal*100).toFixed(1)}%</td></tr>`;
            });
            $('#resolution-body').html(h);
        } else $('#resolution-body').html('<tr><td colspan="3" class="text-muted text-center py-3">No data</td></tr>');
    }).catch(function(){
        console.warn('Failed to load devices data');
        $('#os-body').html('<tr><td colspan="3" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
        $('#resolution-body').html('<tr><td colspan="3" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
