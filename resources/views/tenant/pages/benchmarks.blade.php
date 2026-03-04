@extends('layouts.tenant')

@section('title', 'Competitive Benchmarks')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Competitive Benchmarks</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI &amp; Insights</li>
                        <li class="breadcrumb-item active">Benchmarks</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Controls --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1"><i class="bx bx-bar-chart-square text-primary me-1"></i> Industry Performance Comparison</h5>
                        <p class="text-muted mb-0">Compare your metrics against anonymized industry benchmarks</p>
                    </div>
                    <button class="btn btn-primary" id="btn-load-benchmarks"><i class="bx bx-refresh me-1"></i> Refresh Benchmarks</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Benchmark Cards --}}
    <div class="row" id="benchmark-cards">
        <div class="col-12">
            <div class="text-center py-5" id="benchmark-loading">
                <div class="spinner-border text-primary mb-3" role="status"></div>
                <p class="text-muted">Loading benchmark data...</p>
            </div>
        </div>
    </div>

    {{-- Detailed Table --}}
    <div class="row" id="benchmark-table-row" style="display:none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Detailed Comparison</h4>
                    <div class="table-responsive">
                        <table class="table table-centered mb-0" id="benchmark-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Metric</th>
                                    <th class="text-end">Your Value</th>
                                    <th class="text-end">Industry Avg</th>
                                    <th class="text-end">Percentile</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API = '/analytics/advanced/benchmarks';

    function performanceLabel(percentile) {
        if (percentile >= 90) return '<span class="badge bg-success">Excellent</span>';
        if (percentile >= 70) return '<span class="badge bg-primary">Good</span>';
        if (percentile >= 50) return '<span class="badge bg-info">Average</span>';
        if (percentile >= 30) return '<span class="badge bg-warning">Below Avg</span>';
        return '<span class="badge bg-danger">Needs Work</span>';
    }

    function metricColor(yours, industry, higherIsBetter) {
        if (higherIsBetter === false) return yours <= industry ? 'text-success' : 'text-danger';
        return yours >= industry ? 'text-success' : 'text-danger';
    }

    function formatMetricVal(val, metric) {
        if (typeof val !== 'number') return val || '—';
        const pctMetrics = ['conversion_rate','bounce_rate','cart_abandonment_rate','returning_customer_rate'];
        const dollarMetrics = ['aov','revenue','avg_order_value','clv','average_order_value'];
        const m = metric.toLowerCase();
        if (pctMetrics.some(p => m.includes(p))) return val.toFixed(2) + '%';
        if (dollarMetrics.some(p => m.includes(p))) return '$' + EcomUtils.number(val);
        return EcomUtils.number(val);
    }

    function loadBenchmarks() {
        $('#benchmark-cards').html('<div class="col-12"><div class="text-center py-5" id="benchmark-loading"><div class="spinner-border text-primary mb-3" role="status"></div><p class="text-muted">Loading benchmark data...</p></div></div>');
        $('#benchmark-table-row').hide();

        EcomAPI.get(API).then(json => {
            const data = json.data || {};
            const metrics = data.metrics || data.benchmarks || data.comparisons || [];

            // Build cards
            let cardsHtml = '';
            const items = Array.isArray(metrics) ? metrics : Object.entries(data).map(([k,v]) => ({metric: k, ...v}));
            const lowerBetter = ['bounce_rate','cart_abandonment_rate','refund_rate'];

            items.forEach(m => {
                const name = (m.metric || m.name || m.label || '').replace(/_/g,' ').replace(/\b\w/g, l=>l.toUpperCase());
                const yours = m.your_value ?? m.value ?? m.yours ?? null;
                const industry = m.industry_avg ?? m.benchmark ?? m.average ?? null;
                const pct = m.percentile ?? m.rank ?? null;
                const hb = !lowerBetter.includes((m.metric||'').toLowerCase());
                const colorCls = yours !== null && industry !== null ? metricColor(yours, industry, hb) : '';
                const metricKey = m.metric || '';

                cardsHtml += `<div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-2">${name}</h6>
                            <h4 class="${colorCls} mb-1">${yours !== null ? formatMetricVal(yours, metricKey) : '—'}</h4>
                            <p class="text-muted font-size-12 mb-2">Industry: ${industry !== null ? formatMetricVal(industry, metricKey) : '—'}</p>
                            ${pct !== null ? `<div class="progress mb-1" style="height:6px"><div class="progress-bar ${pct>=70?'bg-success':pct>=40?'bg-warning':'bg-danger'}" style="width:${pct}%"></div></div><small class="text-muted">${pct}th percentile</small>` : ''}
                        </div>
                    </div>
                </div>`;
            });

            if (!cardsHtml) {
                cardsHtml = '<div class="col-12"><div class="text-center text-muted py-5">No benchmark data available.</div></div>';
            }
            $('#benchmark-cards').html(cardsHtml);

            // Build table
            const $tbody = $('#benchmark-table tbody').empty();
            items.forEach(m => {
                const name = (m.metric || m.name || '').replace(/_/g,' ').replace(/\b\w/g, l=>l.toUpperCase());
                const yours = m.your_value ?? m.value ?? null;
                const industry = m.industry_avg ?? m.benchmark ?? null;
                const pct = m.percentile ?? m.rank ?? null;
                const metricKey = m.metric || '';
                $tbody.append(`<tr>
                    <td><strong>${name}</strong></td>
                    <td class="text-end">${yours !== null ? formatMetricVal(yours, metricKey) : '—'}</td>
                    <td class="text-end">${industry !== null ? formatMetricVal(industry, metricKey) : '—'}</td>
                    <td class="text-end">${pct !== null ? pct + 'th' : '—'}</td>
                    <td>${pct !== null ? performanceLabel(pct) : '—'}</td>
                </tr>`);
            });
            if (items.length) $('#benchmark-table-row').show();
        }).catch(err => {
            $('#benchmark-cards').html(`<div class="col-12"><div class="text-center text-danger py-5">${err.message || 'Failed to load benchmarks'}</div></div>`);
        });
    }

    loadBenchmarks();
    $('#btn-load-benchmarks').on('click', loadBenchmarks);
});
</script>
@endsection
