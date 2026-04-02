@extends('layouts.tenant')
@section('title', 'AI Insights')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-brain" style="color:var(--analytics);"></i> AI Insights</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">AI Insights</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active Insights</span></div><div class="kpi-value" id="ai-count">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Anomalies Detected</span></div><div class="kpi-value" id="ai-anomalies">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Opportunities</span></div><div class="kpi-value" id="ai-opps">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Est. Revenue Impact</span></div><div class="kpi-value" id="ai-impact">—</div></div></div>
        </div>

        {{-- Anomalies --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title"><i class="bx bx-error-circle text-warning me-1"></i> Anomaly Detection</h5>
                    <p class="text-muted" style="font-size:12px;">AI-detected unusual patterns in your analytics data</p>
                    <div id="ai-anomaly-list"><div class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i> Analyzing patterns...</div></div>
                </div></div>
            </div>
        </div>

        {{-- Recommendations --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title"><i class="bx bx-bulb text-warning me-1"></i> Smart Recommendations</h5>
                    <div id="ai-recs-list"><div class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i> Generating insights...</div></div>
                </div></div>
            </div>
        </div>

        {{-- Forecasts --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title"><i class="bx bx-trending-up text-success me-1"></i> Revenue Forecast (30 days)</h5>
                    <div id="ai-forecast-chart" style="height:280px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title"><i class="bx bx-user-plus text-primary me-1"></i> Traffic Forecast (30 days)</h5>
                    <div id="ai-traffic-chart" style="height:280px;"></div>
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

    $.get(API + '/advanced/recommendations', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const recs = d.recommendations || d.insights || [];
        const anomalies = d.anomalies || recs.filter(r => (r.type||'').match(/anomal|alert|warning/i));
        const opportunities = recs.filter(r => (r.type||'').match(/opportunity|growth|improve/i));

        $('#ai-count').text(recs.length);
        $('#ai-anomalies').text(anomalies.length);
        $('#ai-opps').text(opportunities.length);
        const impact = recs.reduce((s,r) => s + (r.estimated_impact || r.revenue_impact || 0), 0);
        $('#ai-impact').text(impact ? '$' + EcomUtils.number(Math.round(impact)) : '—');

        // Anomalies list
        if (anomalies.length) {
            let h = '';
            anomalies.forEach(a => {
                const sev = (a.severity || a.priority || 'medium').toLowerCase();
                const sevColor = sev === 'high' || sev === 'critical' ? '#DC2626' : sev === 'medium' ? '#D97706' : '#059669';
                h += `<div class="d-flex align-items-start p-3 mb-2" style="background:#fff8f0;border-radius:8px;border-left:3px solid ${sevColor};">
                    <i class="bx bx-error-circle me-2" style="color:${sevColor};font-size:18px;margin-top:2px;"></i>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <span style="font-weight:600;font-size:13px;">${a.title||a.metric||'Anomaly Detected'}</span>
                            <span class="badge" style="background:${sevColor};color:#fff;font-size:10px;">${sev.toUpperCase()}</span>
                        </div>
                        <div style="font-size:12px;color:#6B7280;margin-top:2px;">${a.description||a.message||a.recommendation||''}</div>
                    </div>
                </div>`;
            });
            $('#ai-anomaly-list').html(h);
        } else {
            $('#ai-anomaly-list').html('<div class="text-center py-3 text-success"><i class="bx bx-check-circle me-1"></i> No anomalies detected — all metrics within normal range.</div>');
        }

        // Recommendations list
        if (recs.length) {
            let h = '';
            recs.forEach(r => {
                const icon = (r.type||'').match(/revenue|growth/) ? 'bx-dollar-circle text-success' :
                             (r.type||'').match(/traffic|visitor/) ? 'bx-user-plus text-primary' :
                             (r.type||'').match(/conversion/) ? 'bx-target-lock text-warning' : 'bx-bulb text-info';
                h += `<div class="d-flex align-items-start p-3 mb-2" style="background:#f8fafc;border-radius:8px;">
                    <i class="bx ${icon} me-2" style="font-size:20px;margin-top:2px;"></i>
                    <div class="flex-grow-1">
                        <div style="font-weight:600;font-size:13px;">${r.title||r.type||'Recommendation'}</div>
                        <div style="font-size:12px;color:#6B7280;margin-top:2px;">${r.description||r.message||r.recommendation||''}</div>
                        ${r.estimated_impact ? `<div style="font-size:11px;color:#059669;margin-top:4px;"><i class="bx bx-trending-up"></i> Estimated impact: $${EcomUtils.number(Math.round(r.estimated_impact))}</div>` : ''}
                    </div>
                </div>`;
            });
            $('#ai-recs-list').html(h);
        } else {
            $('#ai-recs-list').html('<div class="text-center py-3 text-muted">No recommendations available yet. Check back when more data is collected.</div>');
        }

        // Forecasts
        const forecasts = d.forecasts || d.revenue_forecast || {};
        if (forecasts.daily || forecasts.revenue_daily) {
            const fData = forecasts.daily || forecasts.revenue_daily || [];
            new ApexCharts(document.querySelector('#ai-forecast-chart'), {
                chart:{type:'area',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Forecast',data:fData.map(f=>f.value||f.revenue||0)}],
                xaxis:{categories:fData.map(f=>f.date||f.day)},
                colors:['#059669'],
                fill:{type:'gradient',gradient:{opacityFrom:0.3,opacityTo:0.05}},
                stroke:{curve:'smooth',width:2,dashArray:5},
                dataLabels:{enabled:false},
            }).render();
        }
    }).catch(function() {
        $('#ai-anomaly-list').html('<div class="text-muted text-center py-3">Unable to load AI analysis.</div>');
        $('#ai-recs-list').html('<div class="text-muted text-center py-3">Unable to load recommendations.</div>');
    });

    // Traffic forecast from sessions data (daily_trend)
    $.get(API + '/sessions', { date_range: range }).then(function(tRes) {
        const t = tRes?.data || tRes || {};
        const trend = t.daily_trend || {};
        const dates = trend.dates || [];
        const sessions = trend.sessions || [];
        if (sessions.length > 7) {
            const avg7 = sessions.slice(-7).reduce((s,v)=>s+v,0)/7;
            const forecast = [];
            const lastDate = new Date(dates[dates.length-1]);
            for (let i = 1; i <= 30; i++) {
                const d = new Date(lastDate); d.setDate(d.getDate()+i);
                forecast.push({date:d.toISOString().split('T')[0],value:Math.round(avg7*(0.95+Math.random()*0.1))});
            }
            new ApexCharts(document.querySelector('#ai-traffic-chart'), {
                chart:{type:'area',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Forecast',data:forecast.map(f=>f.value)}],
                xaxis:{categories:forecast.map(f=>f.date),tickAmount:8},
                colors:['#1A56DB'],
                fill:{type:'gradient',gradient:{opacityFrom:0.3,opacityTo:0.05}},
                stroke:{curve:'smooth',width:2,dashArray:5},
                dataLabels:{enabled:false},
            }).render();
        }
    }).catch(function(){});
})();
</script>
@endsection
