@extends('layouts.tenant')
@section('title', 'Abandoned Carts')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-cart-alt" style="color:#DC2626;"></i> Abandoned Carts</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Abandoned Carts</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Carts Created</span></div><div class="kpi-value" id="ac-created">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Carts Abandoned</span></div><div class="kpi-value" id="ac-abandoned">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card" style="border-left-color:#DC2626;"><div class="kpi-header"><span class="kpi-label">Abandonment Rate</span></div><div class="kpi-value" id="ac-rate" style="color:#DC2626;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Lost Revenue</span></div><div class="kpi-value" id="ac-lost">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Most Abandoned Products</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Product</th><th class="text-end">Added to Cart</th><th class="text-end">Abandoned</th><th class="text-end">Abandon Rate</th><th class="text-end">Lost Revenue</th></tr></thead>
                            <tbody id="ac-body"><tr><td colspan="6" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Abandonment by Product</h5>
                    <div id="ac-chart" style="height:320px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Abandonment Trend</h5>
                    <div id="ac-trend-chart" style="height:260px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Recovery Recommendations</h5>
                    <div id="ac-recs" class="py-2"><span class="text-muted">Loading AI recommendations...</span></div>
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

    $.when(
        $.get(API + '/products', { date_range: range }),
        $.get(API + '/revenue', { date_range: range })
    ).then(function(prodRes, revRes) {
        const prod = prodRes[0]?.data || prodRes[0] || {};
        const rev = revRes[0]?.data || revRes[0] || {};
        const daily = rev.daily || {};

        // products API: { cart_abandonment: [{product_name, cart_adds, purchases, abandonments, abandonment_rate}] }
        const abandoned = prod.cart_abandonment || prod.cart_abandonment_products || [];
        const totalCartAdds = abandoned.reduce((s,p) => s + (p.cart_adds || 0), 0);
        const totalAbandoned = abandoned.reduce((s,p) => s + (p.abandonments || 0), 0);
        const avgRate = totalCartAdds > 0 ? (totalAbandoned / totalCartAdds * 100) : 0;

        $('#ac-created').text(EcomUtils.number(totalCartAdds));
        $('#ac-abandoned').text(EcomUtils.number(totalAbandoned));
        $('#ac-rate').text(avgRate.toFixed(1)+'%');
        const aov = daily.average_order_value || 0;
        const lostRev = aov > 0 ? Math.round(totalAbandoned * aov) : 0;
        $('#ac-lost').text('$'+EcomUtils.number(lostRev));

        if (abandoned.length) {
            let h = '';
            abandoned.forEach((p, i) => {
                const added = p.cart_adds || 0;
                const aband = p.abandonments || 0;
                const rate = p.abandonment_rate || (added > 0 ? (aband/added*100) : 0);
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;font-weight:500;">${p.product_name||p.name||'—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(added)}</td>
                    <td class="text-end mono" style="font-size:12px;color:#DC2626;">${EcomUtils.number(aband)}</td>
                    <td class="text-end mono" style="font-size:12px;">${rate.toFixed(1)}%</td>
                    <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(Math.round(aband * aov))}</td>
                </tr>`;
            });
            $('#ac-body').html(h);

            const top = abandoned.slice(0,8);
            new ApexCharts(document.querySelector('#ac-chart'), {
                chart:{type:'bar',height:320,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Abandoned',data:top.map(p=>p.abandonments||0)}],
                xaxis:{categories:top.map(p=>(p.product_name||p.name||'?').substring(0,16))},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#DC2626'],dataLabels:{enabled:false},
            }).render();
        } else {
            $('#ac-body').html('<tr><td colspan="6" class="text-muted text-center py-3">No abandonment data</td></tr>');
        }

        // Trend — use revenue daily trend (parallel arrays)
        const dates = daily.dates || [];
        const revenues = daily.revenues || [];
        if (dates.length) {
            new ApexCharts(document.querySelector('#ac-trend-chart'), {
                chart:{type:'area',height:260,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Revenue Trend',data:revenues}],
                xaxis:{categories:dates,tickAmount:Math.min(dates.length,10)},
                colors:['#DC2626'],
                fill:{type:'gradient',gradient:{opacityFrom:0.3,opacityTo:0.05}},
                stroke:{curve:'smooth',width:2},
                dataLabels:{enabled:false},
                yaxis:{labels:{formatter:v=>'$'+EcomUtils.number(Math.round(v))}},
            }).render();
        }

        // Recovery recommendations from AI
        $.get(API + '/advanced/recommendations', { date_range: range }).then(function(aiRes) {
            const recs = aiRes?.data?.recommendations || aiRes?.recommendations || [];
            const cartRecs = recs.filter(r => (r.title||r.type||'').toLowerCase().match(/cart|abandon|recover/)) || recs.slice(0,3);
            if (cartRecs.length) {
                let h = '';
                cartRecs.forEach(r => {
                    h += `<div class="d-flex align-items-start mb-3 p-2" style="background:#f8f9fa;border-radius:8px;">
                        <i class="bx bx-bulb text-warning me-2" style="font-size:18px;margin-top:2px;"></i>
                        <div><div style="font-weight:600;font-size:13px;">${r.title||r.type||'Recovery Tip'}</div>
                        <div style="font-size:12px;color:#6B7280;">${r.description||r.message||r.recommendation||''}</div></div>
                    </div>`;
                });
                $('#ac-recs').html(h);
            } else {
                $('#ac-recs').html('<div class="text-muted">No specific recovery recommendations available.</div>');
            }
        }).catch(()=> $('#ac-recs').html('<div class="text-muted">Recommendations unavailable.</div>'));
    });
})();
</script>
@endsection
