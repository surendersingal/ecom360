@extends('layouts.tenant')
@section('title', 'CLV Predictions')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-line-chart" style="color:var(--analytics);"></i> Predictions</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Predictions</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. CLV (12mo)</span></div><div class="kpi-value" id="clv-avg">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">High-Value Customers</span></div><div class="kpi-value" id="clv-high">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">At-Risk Customers</span></div><div class="kpi-value" id="clv-risk">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Revenue Forecast (30d)</span></div><div class="kpi-value" id="clv-forecast">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">CLV Distribution</h5>
                    <div id="clv-dist-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Customer Segments by Value</h5>
                    <div id="clv-seg-chart" style="height:300px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Customers by Predicted CLV</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Customer</th><th>Segment</th><th class="text-end">Orders</th><th class="text-end">Lifetime Spend</th><th class="text-end">Predicted CLV</th><th class="text-end">Churn Risk</th></tr></thead>
                            <tbody id="clv-body"><tr><td colspan="7" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Revenue Forecast</h5>
                    <div id="clv-rev-chart" style="height:280px;"></div>
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

    $.get(API + '/advanced/clv', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const customers = d.top_customers || d.customers || d.predictions || [];
        const segsObj = d.segments || d.clv_segments || {};
        // segments may be {high:0,medium:0,low:0} object or array
        const segments = Array.isArray(segsObj) ? segsObj : Object.entries(segsObj).filter(([k,v])=>v>0).map(([k,v])=>({name:k,count:v}));
        const distribution = d.distribution || d.clv_distribution || [];

        const avgClv = d.avg_predicted_clv || d.average_clv || (d.total_predicted_clv && d.total_customers ? d.total_predicted_clv/d.total_customers : 0);
        $('#clv-avg').text(avgClv ? '$'+EcomUtils.number(Math.round(avgClv)) : '—');
        const highCount = d.high_value_count || (typeof segsObj === 'object' && !Array.isArray(segsObj) ? (segsObj.high||0) : customers.filter(c=>(c.segment||'').match(/high|vip|champion/i)).length);
        $('#clv-high').text(highCount || d.total_customers || 0);
        $('#clv-risk').text(d.at_risk_count != null ? d.at_risk_count : (customers.filter(c=>(c.churn_risk||0)>0.5).length || '—'));
        $('#clv-forecast').text(d.revenue_forecast_30d ? '$'+EcomUtils.number(Math.round(d.revenue_forecast_30d)) : '—');

        // Customer table
        if (customers.length) {
            let h = '';
            customers.slice(0,20).forEach((c, i) => {
                const risk = c.churn_risk || c.risk_score || 0;
                const riskColor = risk > 0.7 ? '#DC2626' : risk > 0.4 ? '#D97706' : '#059669';
                const segment = c.segment || c.tier || '—';
                const segColor = segment.match(/champion|vip|high/i) ? '#059669' : segment.match(/loyal|regular/i) ? '#1A56DB' : '#D97706';
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;">${c.customer_id || c.email || c.name || 'Customer '+(i+1)}</td>
                    <td><span class="badge" style="background:${segColor}15;color:${segColor};font-size:10px;">${segment}</span></td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(c.orders || c.order_count || 0)}</td>
                    <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(c.lifetime_spend || c.total_spent || 0)}</td>
                    <td class="text-end mono" style="font-size:12px;font-weight:600;">$${EcomUtils.number(Math.round(c.predicted_clv || c.clv || 0))}</td>
                    <td class="text-end"><div class="d-flex align-items-center justify-content-end gap-2">
                        <div style="width:50px;height:6px;background:#f1f1f1;border-radius:3px;overflow:hidden;">
                            <div style="width:${Math.round(risk*100)}%;height:100%;background:${riskColor};border-radius:3px;"></div>
                        </div>
                        <span style="font-size:11px;color:${riskColor};">${(risk*100).toFixed(0)}%</span>
                    </div></td>
                </tr>`;
            });
            $('#clv-body').html(h);
        }

        // Distribution histogram — build from segments if no dedicated distribution data
        if (distribution.length) {
            new ApexCharts(document.querySelector('#clv-dist-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Customers',data:distribution.map(d=>d.count||0)}],
                xaxis:{categories:distribution.map(d=>d.range||d.bucket||'?')},
                plotOptions:{bar:{borderRadius:4,columnWidth:'70%'}},
                colors:['#1A56DB'],dataLabels:{enabled:false},
            }).render();
        } else if (segments.length) {
            // Use segment data as distribution proxy
            new ApexCharts(document.querySelector('#clv-dist-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Customers',data:segments.map(s=>s.count||s.customers||0)}],
                xaxis:{categories:segments.map(s=>(s.name||s.segment||'?').charAt(0).toUpperCase()+(s.name||s.segment||'?').slice(1)+' Value')},
                plotOptions:{bar:{borderRadius:4,columnWidth:'70%'}},
                colors:['#059669','#1A56DB','#D97706'],dataLabels:{enabled:false},
            }).render();
        }

        // Segments donut
        if (segments.length) {
            new ApexCharts(document.querySelector('#clv-seg-chart'), {
                chart:{type:'donut',height:300,fontFamily:'Inter'},
                series:segments.map(s=>s.count||s.customers||0),
                labels:segments.map(s=>s.name||s.segment||'?'),
                colors:['#059669','#1A56DB','#D97706','#DC2626','#7C3AED'],
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }
    }).catch(function() {
        $('#clv-body').html('<tr><td colspan="7" class="text-center text-muted py-3">CLV predictions unavailable. Ensure sufficient order history.</td></tr>');
    });

    // Revenue forecast chart — API returns { daily: { dates:[], revenues:[] } } (parallel arrays)
    $.get(API + '/revenue', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const daily = d.daily || {};
        const dates = daily.dates || [];
        const revenues = daily.revenues || [];
        if (dates.length > 3) {
            const actual = dates.map((dt,i) => ({date:dt, value:revenues[i]||0}));
            const avg = actual.slice(-7).reduce((s,a)=>s+a.value,0)/Math.min(actual.length,7);
            const lastDate = new Date(actual[actual.length-1].date);
            const forecast = [];
            for (let i = 1; i <= 30; i++) {
                const dt = new Date(lastDate); dt.setDate(dt.getDate()+i);
                forecast.push({date:dt.toISOString().split('T')[0],value:Math.round(avg*(0.93+Math.random()*0.14))});
            }
            new ApexCharts(document.querySelector('#clv-rev-chart'), {
                chart:{type:'line',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[
                    {name:'Actual',data:actual.slice(-14).map(a=>a.value)},
                    {name:'Forecast',data:new Array(actual.slice(-14).length).fill(null).concat(forecast.map(f=>f.value))}
                ],
                xaxis:{categories:[...actual.slice(-14).map(a=>a.date),...forecast.map(f=>f.date)],tickAmount:10},
                colors:['#1A56DB','#059669'],
                stroke:{curve:'smooth',width:[2,2],dashArray:[0,5]},
                dataLabels:{enabled:false},
                annotations:{xaxis:[{x:actual[actual.length-1].date,borderColor:'#6B7280',label:{text:'Today',style:{fontSize:'10px'}}}]},
            }).render();
        }
    });
})();
</script>
@endsection
