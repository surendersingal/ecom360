@extends('layouts.tenant')
@section('title', 'Conversion Funnel')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-filter-alt" style="color:var(--analytics);"></i> Conversion Funnel</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Funnel</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Sessions</span></div><div class="kpi-value" id="fn-sessions">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Completed Purchases</span></div><div class="kpi-value" id="fn-completed">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Overall Conv. Rate</span></div><div class="kpi-value" id="fn-rate">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Biggest Drop-off</span></div><div class="kpi-value" id="fn-dropoff" style="font-size:13px;">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Ecommerce Funnel</h5>
                    <div id="funnel-visual" class="py-3"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Funnel Step Breakdown</h5>
                    <div id="fn-bar-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Step-by-Step Drop-off</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Step</th><th class="text-end">Users</th><th class="text-end">Drop-off</th><th class="text-end">Conv. to Next</th><th class="text-end">Overall %</th></tr></thead>
                            <tbody id="fn-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
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
    const stepColors = ['#1A56DB','#0891B2','#059669','#D97706','#DC2626'];
    const defaultSteps = [
        {name:'Visit',label:'Site Visits'},
        {name:'Product View',label:'Product Viewed'},
        {name:'Add to Cart',label:'Added to Cart'},
        {name:'Checkout',label:'Checkout Started'},
        {name:'Purchase',label:'Purchase Completed'}
    ];

    $.get(API + '/funnel', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        // API: { stages: [{stage, unique_sessions, drop_off_pct}], overall_conversion_pct }
        let steps = d.stages || d.steps || d.funnel_steps || d.funnel || [];

        // Map API field names to template expectations
        steps = steps.map(s => ({
            name: s.stage || s.name || s.label || 'Unknown',
            count: s.unique_sessions || s.count || s.users || s.value || 0,
            drop_off_pct: s.drop_off_pct || 0
        }));

        if (!steps.length) {
            $('#fn-sessions').text('0');
            $('#fn-completed').text('0');
            $('#fn-rate').text('0%');
            $('#fn-dropoff').text('No data');
            $('#funnel-visual').html('<div class="text-muted text-center py-4">No funnel data available for this period</div>');
            $('#fn-body').html('<tr><td colspan="5" class="text-muted text-center py-3">No data</td></tr>');
            return;
        }

        const first = steps[0]?.count || 1;
        const last = steps[steps.length-1]?.count || 0;
        const overallConv = d.overall_conversion_pct || (last/first*100);

        $('#fn-sessions').text(EcomUtils.number(first));
        $('#fn-completed').text(EcomUtils.number(last));
        $('#fn-rate').text(overallConv.toFixed(2)+'%');

        // Find biggest drop-off
        let maxDrop = 0, maxDropName = '';
        steps.forEach((s, i) => {
            if (i > 0) {
                const prev = steps[i-1].count || 0;
                const curr = s.count || 0;
                const drop = prev - curr;
                if (drop > maxDrop) { maxDrop = drop; maxDropName = steps[i-1].name + ' → ' + s.name; }
            }
        });
        $('#fn-dropoff').text(maxDropName || '—');

        // Visual funnel
        let funnelHtml = '<div class="d-flex flex-column align-items-center" style="gap:4px;">';
        steps.forEach((s, i) => {
            const cnt = s.count || 0;
            const pct = (cnt/first*100).toFixed(1);
            const widthPct = Math.max(20, pct);
            const color = stepColors[i % stepColors.length];
            const dropPct = i > 0 ? (s.drop_off_pct || ((1 - cnt/(steps[i-1].count||1))*100)).toFixed(1) : 0;
            funnelHtml += `
            <div style="width:${widthPct}%;background:${color};color:#fff;text-align:center;padding:14px 12px;border-radius:6px;position:relative;min-width:180px;">
                <div style="font-weight:600;font-size:14px;">${s.name}</div>
                <div style="font-size:20px;font-weight:700;">${EcomUtils.number(cnt)}</div>
                <div style="font-size:11px;opacity:0.9;">${pct}% of total</div>
                ${i > 0 ? `<div style="position:absolute;right:-80px;top:50%;transform:translateY(-50%);font-size:11px;color:#DC2626;font-weight:600;">-${dropPct}%</div>` : ''}
            </div>`;
        });
        funnelHtml += '</div>';
        $('#funnel-visual').html(funnelHtml);

        // Bar chart
        new ApexCharts(document.querySelector('#fn-bar-chart'), {
            chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
            series:[{name:'Users',data:steps.map(s=>s.count||0)}],
            xaxis:{categories:steps.map(s=>s.name)},
            plotOptions:{bar:{borderRadius:6,columnWidth:'55%',distributed:true}},
            colors:stepColors,
            dataLabels:{enabled:true,formatter:v=>EcomUtils.number(v),style:{fontSize:'11px'}},
            legend:{show:false},
        }).render();

        // Table
        let h = '';
        steps.forEach((s, i) => {
            const cnt = s.count || 0;
            const prev = i > 0 ? (steps[i-1].count || 0) : cnt;
            const drop = i > 0 ? prev - cnt : 0;
            const convNext = i > 0 ? (prev > 0 ? (cnt/prev*100).toFixed(1) : '0.0') : '100.0';
            h += `<tr>
                <td style="font-size:12px;font-weight:500;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${stepColors[i%5]};margin-right:6px;"></span>${s.name}</td>
                <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(cnt)}</td>
                <td class="text-end mono" style="font-size:12px;color:#DC2626;">${i>0?'-'+EcomUtils.number(drop):'—'}</td>
                <td class="text-end mono" style="font-size:12px;">${convNext}%</td>
                <td class="text-end mono" style="font-size:12px;">${(cnt/first*100).toFixed(1)}%</td>
            </tr>`;
        });
        $('#fn-body').html(h);
    }).catch(function(){
        console.warn('Failed to load funnel data');
        $('#funnel-visual').html('<div class="text-center text-muted py-4">Unable to load data. Please try again.</div>');
        $('#fn-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
