@extends('layouts.tenant')
@section('title', 'Competitive Benchmarks')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-bar-chart-alt-2" style="color:var(--analytics);"></i> Benchmarks</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Benchmarks</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="alert alert-info border-0 rounded-3 d-flex align-items-center mb-4" style="background:#EFF6FF;">
            <i class="bx bx-info-circle me-2" style="font-size:20px;color:#1A56DB;"></i>
            <div style="font-size:13px;">Benchmarks compare your store's performance against industry averages and similar stores in your category.</div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card" data-module="analytics"><div class="card-body text-center py-4">
                    <div style="font-size:12px;color:#6B7280;">Your Conv. Rate</div>
                    <div id="bm-cr" style="font-size:28px;font-weight:700;">—</div>
                    <div id="bm-cr-vs" style="font-size:12px;"></div>
                </div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card" data-module="analytics"><div class="card-body text-center py-4">
                    <div style="font-size:12px;color:#6B7280;">Your AOV</div>
                    <div id="bm-aov" style="font-size:28px;font-weight:700;">—</div>
                    <div id="bm-aov-vs" style="font-size:12px;"></div>
                </div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card" data-module="analytics"><div class="card-body text-center py-4">
                    <div style="font-size:12px;color:#6B7280;">Your Bounce Rate</div>
                    <div id="bm-bounce" style="font-size:28px;font-weight:700;">—</div>
                    <div id="bm-bounce-vs" style="font-size:12px;"></div>
                </div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card" data-module="analytics"><div class="card-body text-center py-4">
                    <div style="font-size:12px;color:#6B7280;">Your Cart Abandon</div>
                    <div id="bm-cart" style="font-size:28px;font-weight:700;">—</div>
                    <div id="bm-cart-vs" style="font-size:12px;"></div>
                </div></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Performance vs Industry</h5>
                    <div id="bm-radar-chart" style="height:350px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Metric Comparison</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Metric</th><th class="text-end">Your Store</th><th class="text-end">Industry Avg.</th><th class="text-end">Difference</th><th class="text-center">Status</th></tr></thead>
                            <tbody id="bm-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Improvement Recommendations</h5>
                    <div id="bm-recs"><div class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></div></div>
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

    $.get(API + '/advanced/benchmarks', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const msg = d.message || '';

        // Normalize metrics: API may return an object {metric_name: {value, benchmark, percentile}} or an array
        let rawMetrics = d.comparison || d.tenant_metrics || d.metrics || d.benchmarks || d.comparisons || [];
        let metrics = [];
        if (Array.isArray(rawMetrics)) {
            metrics = rawMetrics;
        } else if (typeof rawMetrics === 'object' && rawMetrics !== null) {
            // Convert object format to array
            const nameMap = {
                conversion_rate: 'Conversion Rate',
                aov: 'Avg Order Value',
                cart_abandonment_rate: 'Cart Abandonment',
                bounce_rate: 'Bounce Rate',
                returning_customer_rate: 'Returning Customers',
                avg_session_duration_seconds: 'Avg Session Duration',
                revenue_per_session: 'Revenue / Session',
            };
            metrics = Object.entries(rawMetrics).map(function([key, v]) {
                return {
                    name: nameMap[key] || key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                    yours: v.value ?? 0,
                    value: v.value ?? 0,
                    industry: v.benchmark ?? null,
                    average: v.benchmark ?? null,
                    percentile: v.percentile ?? null,
                };
            });
        }

        const recs = d.recommendations || d.insights || [];
        const score = d.overall_score || {};

        // If API returned a message (e.g. not enough data), show it
        if (msg && !metrics.length) {
            $('#bm-body').html(`<tr><td colspan="5" class="text-center text-muted py-3">${msg}</td></tr>`);
            $('#bm-recs').html(`<div class="text-center py-3 text-muted"><i class="bx bx-info-circle me-1"></i> ${msg}</div>`);
            return;
        }

        // Helper
        function renderVs(el, yours, industry, lowerBetter) {
            if (industry === null || industry === undefined) {
                $(el).html('<span style="color:#9CA3AF;">No benchmark data yet</span>');
                return;
            }
            const diff = yours - industry;
            const better = lowerBetter ? diff < 0 : diff > 0;
            const arrow = better ? '↑' : '↓';
            const color = better ? '#059669' : '#DC2626';
            $(el).html(`<span style="color:${color};">${arrow} ${Math.abs(diff).toFixed(1)} vs industry avg</span>`);
        }

        // Find specific metrics
        const crMetric = metrics.find(m => (m.name||m.metric||'').match(/conv/i)) || {};
        const aovMetric = metrics.find(m => (m.name||m.metric||'').match(/aov|order.value/i)) || {};
        const bounceMetric = metrics.find(m => (m.name||m.metric||'').match(/bounce/i)) || {};
        const cartMetric = metrics.find(m => (m.name||m.metric||'').match(/cart|abandon/i)) || {};

        $('#bm-cr').text((crMetric.yours || crMetric.value || 0).toFixed(2) + '%');
        renderVs('#bm-cr-vs', crMetric.yours||0, crMetric.industry||crMetric.average||2.5, false);

        $('#bm-aov').text('$' + (aovMetric.yours || aovMetric.value || 0).toFixed(0));
        renderVs('#bm-aov-vs', aovMetric.yours||0, aovMetric.industry||aovMetric.average||85, false);

        $('#bm-bounce').text((bounceMetric.yours || bounceMetric.value || 0).toFixed(1) + '%');
        renderVs('#bm-bounce-vs', bounceMetric.yours||0, bounceMetric.industry||bounceMetric.average||45, true);

        $('#bm-cart').text((cartMetric.yours || cartMetric.value || 0).toFixed(1) + '%');
        renderVs('#bm-cart-vs', cartMetric.yours||0, cartMetric.industry||cartMetric.average||70, true);

        // Table
        if (metrics.length) {
            let h = '';
            metrics.forEach(m => {
                const yours = m.yours || m.value || 0;
                const ind = m.industry ?? m.average ?? null;
                const lowerBetter = (m.name||m.metric||'').match(/bounce|abandon|exit/i);
                if (ind !== null) {
                    const diff = yours - ind;
                    const better = lowerBetter ? diff < 0 : diff > 0;
                    h += `<tr>
                        <td style="font-size:12px;font-weight:500;">${m.name||m.metric||'—'}</td>
                        <td class="text-end mono" style="font-size:12px;">${typeof yours==='number'?yours.toFixed(2):'—'}</td>
                        <td class="text-end mono" style="font-size:12px;">${typeof ind==='number'?ind.toFixed(2):'—'}</td>
                        <td class="text-end mono" style="font-size:12px;color:${better?'#059669':'#DC2626'};">${diff>0?'+':''}${diff.toFixed(2)}</td>
                        <td class="text-center"><span class="badge bg-${better?'success':'danger'}" style="font-size:10px;">${better?'Above':'Below'}</span></td>
                    </tr>`;
                } else {
                    h += `<tr>
                        <td style="font-size:12px;font-weight:500;">${m.name||m.metric||'—'}</td>
                        <td class="text-end mono" style="font-size:12px;">${typeof yours==='number'?yours.toFixed(2):'—'}</td>
                        <td class="text-end mono" style="font-size:12px;color:#9CA3AF;">—</td>
                        <td class="text-end mono" style="font-size:12px;color:#9CA3AF;">—</td>
                        <td class="text-center"><span class="badge bg-secondary" style="font-size:10px;">Pending</span></td>
                    </tr>`;
                }
            });
            $('#bm-body').html(h);
        }

        // Radar chart
        if (metrics.length >= 3) {
            new ApexCharts(document.querySelector('#bm-radar-chart'), {
                chart:{type:'radar',height:350,fontFamily:'Inter',toolbar:{show:false}},
                series:[
                    {name:'Your Store',data:metrics.slice(0,6).map(m=>m.yours_normalized||m.percentile||(m.yours||0))},
                    {name:'Industry Avg',data:metrics.slice(0,6).map(m=>m.industry_normalized||50)}
                ],
                xaxis:{categories:metrics.slice(0,6).map(m=>m.name||m.metric||'?')},
                colors:['#1A56DB','#D1D5DB'],
                stroke:{width:2},
                fill:{opacity:[0.2,0.05]},
                markers:{size:3},
                legend:{position:'bottom'},
            }).render();
        }

        // Recommendations — generate from metrics comparison if API doesn't provide them
        if (recs.length) {
            let h = '';
            recs.forEach(r => {
                h += `<div class="d-flex align-items-start p-3 mb-2" style="background:#f8fafc;border-radius:8px;">
                    <i class="bx bx-target-lock text-primary me-2" style="font-size:18px;margin-top:2px;"></i>
                    <div><div style="font-weight:600;font-size:13px;">${r.title||r.metric||'Improvement Area'}</div>
                    <div style="font-size:12px;color:#6B7280;margin-top:2px;">${r.description||r.recommendation||r.message||''}</div></div>
                </div>`;
            });
            $('#bm-recs').html(h);
        } else if (metrics.length) {
            // Auto-generate recommendations from metrics with below-benchmark values
            const belowMetrics = metrics.filter(m => {
                const ind = m.industry ?? m.average ?? null;
                if (ind === null) return false;
                const yours = m.yours || m.value || 0;
                const lowerBetter = (m.name||'').match(/bounce|abandon|exit/i);
                return lowerBetter ? yours > ind : yours < ind;
            });
            if (belowMetrics.length) {
                let h = '';
                belowMetrics.forEach(m => {
                    h += `<div class="d-flex align-items-start p-3 mb-2" style="background:#FEF2F2;border-radius:8px;">
                        <i class="bx bx-trending-down text-danger me-2" style="font-size:18px;margin-top:2px;"></i>
                        <div><div style="font-weight:600;font-size:13px;">Improve ${m.name||m.metric}</div>
                        <div style="font-size:12px;color:#6B7280;margin-top:2px;">Your ${m.name||m.metric} is ${(m.yours||m.value||0).toFixed(2)} vs industry average of ${(m.industry||m.average||0).toFixed(2)}. Consider optimizing this metric to match or exceed the benchmark.</div></div>
                    </div>`;
                });
                $('#bm-recs').html(h);
            } else {
                $('#bm-recs').html('<div class="text-center py-3 text-success"><i class="bx bx-check-circle me-1"></i> Your store is performing at or above industry benchmarks.</div>');
            }
        } else if (score.label === 'insufficient_data') {
            $('#bm-recs').html('<div class="text-center py-3 text-muted"><i class="bx bx-info-circle me-1"></i> Not enough data yet. Benchmarks will be generated as more analytics data is collected.</div>');
        } else {
            $('#bm-recs').html('<div class="text-center py-3 text-success"><i class="bx bx-check-circle me-1"></i> Your store is performing at or above industry benchmarks.</div>');
        }
    }).catch(function() {
        $('#bm-body').html('<tr><td colspan="5" class="text-center text-muted py-3">Benchmarks not available. Ensure analytics data is being collected.</td></tr>');
        $('#bm-recs').html('');
    });
})();
</script>
@endsection
