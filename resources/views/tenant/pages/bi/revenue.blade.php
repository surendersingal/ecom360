@extends('layouts.tenant')
@section('title', 'Revenue Command Center')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-line-chart" style="color:var(--analytics);"></i> Revenue Command Center</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Revenue</li>
                </ol>
            </nav>
        </div>
        <div>
            <select id="breakdown-dim" class="form-select form-select-sm d-inline-block w-auto">
                <option value="category">By Category</option>
                <option value="payment">By Payment</option>
                <option value="status">By Status</option>
                <option value="coupon">By Coupon</option>
                <option value="day_of_week">By Day</option>
                <option value="hour">By Hour</option>
                <option value="new_vs_returning">New vs Returning</option>
            </select>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Period Tabs --}}
        <ul class="nav nav-pills mb-3" id="period-tabs">
            <li class="nav-item"><a class="nav-link" data-period="today" href="#">Today</a></li>
            <li class="nav-item"><a class="nav-link" data-period="week" href="#">This Week</a></li>
            <li class="nav-item"><a class="nav-link active" data-period="month" href="#">This Month</a></li>
            <li class="nav-item"><a class="nav-link" data-period="year" href="#">This Year</a></li>
        </ul>

        {{-- KPI Row --}}
        <div class="row g-3 mb-4" id="kpi-row">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card">
                    <div class="kpi-header"><span class="kpi-label">Revenue</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                    <div class="kpi-value" id="kpi-revenue">—</div>
                    <div class="kpi-sub" id="kpi-revenue-change"></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card">
                    <div class="kpi-header"><span class="kpi-label">Orders</span><span class="kpi-icon orders"><i class="bx bx-cart"></i></span></div>
                    <div class="kpi-value" id="kpi-orders">—</div>
                    <div class="kpi-sub" id="kpi-orders-change"></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card">
                    <div class="kpi-header"><span class="kpi-label">AOV</span><span class="kpi-icon conversion"><i class="bx bx-trending-up"></i></span></div>
                    <div class="kpi-value" id="kpi-aov">—</div>
                    <div class="kpi-sub" id="kpi-aov-change"></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card">
                    <div class="kpi-header"><span class="kpi-label">Net Revenue</span><span class="kpi-icon revenue"><i class="bx bx-wallet"></i></span></div>
                    <div class="kpi-value" id="kpi-net">—</div>
                    <div class="kpi-sub" id="kpi-net-change"></div>
                </div>
            </div>
        </div>

        {{-- Charts Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Revenue Trend (90 Days)</h5>
                        <div id="trend-chart" style="height:360px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Revenue by Hour (Today)</h5>
                        <div id="hourly-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Breakdown + Top Performers --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Revenue Breakdown <small class="text-muted" id="breakdown-label">by Category</small></h5>
                        <div id="breakdown-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Top Performers</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-nowrap mb-0" id="top-products-table">
                                <thead><tr><th>#</th><th>Product</th><th class="text-end">Revenue</th><th class="text-end">Qty</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Anomaly Alerts --}}
        <div class="row g-3 mb-4" id="anomaly-section" style="display:none;">
            <div class="col-12">
                <div class="card border-warning" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title text-warning"><i class="bx bx-error"></i> Revenue Anomalies Detected</h5>
                        <div id="anomaly-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

let trendChartObj, hourlyChartObj, breakdownChartObj;

async function fetchJson(url) {
    const res = await fetch(url, { headers });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'API error');
    return json.data;
}

async function loadCommandCenter() {
    try {
        const d = await fetchJson(`${API}/bi/intel/revenue/command-center`);
        renderKpis(d);
    } catch (e) { console.error('Command center:', e); }
}

function renderKpis(d) {
    const p = document.querySelector('#period-tabs .active')?.dataset?.period || 'month';
    const pd = d[p] || {};

    $('#kpi-revenue').text('₹' + EcomUtils.number(pd.revenue || 0));
    $('#kpi-orders').text(EcomUtils.number(pd.orders || 0));
    $('#kpi-aov').text('₹' + EcomUtils.number(pd.aov || 0));
    $('#kpi-net').text('₹' + EcomUtils.number(pd.net || 0));

    const changeBadge = (val) => {
        if (val == null || isNaN(val)) return '';
        const cls = val >= 0 ? 'text-success' : 'text-danger';
        const icon = val >= 0 ? 'bx-up-arrow-alt' : 'bx-down-arrow-alt';
        return `<span class="${cls}"><i class="bx ${icon}"></i> ${Math.abs(val).toFixed(1)}%</span> vs prev`;
    };

    $('#kpi-revenue-change').html(changeBadge(pd.revenue_change));
    $('#kpi-orders-change').html(changeBadge(pd.orders_change));
    $('#kpi-aov-change').html(changeBadge(pd.aov_change));
    $('#kpi-net-change').html(changeBadge(pd.net_change));

    window._ccData = d;
}

async function loadTrend() {
    try {
        const d = await fetchJson(`${API}/bi/intel/revenue/trend?days=90`);
        const dates = d.daily.map(r => r.date);
        const rev   = d.daily.map(r => r.revenue);
        const ma7   = d.daily.map(r => r.ma7);
        const ma30  = d.daily.map(r => r.ma30);

        const opts = {
            chart: { type: 'area', height: 360, toolbar: { show: true } },
            series: [
                { name: 'Revenue', data: rev },
                { name: '7d MA',   data: ma7 },
                { name: '30d MA',  data: ma30 },
            ],
            xaxis: { categories: dates, labels: { rotate: -45, rotateAlways: false }, tickAmount: 15 },
            yaxis: { labels: { formatter: v => '₹' + (v >= 1000 ? (v/1000).toFixed(0) + 'K' : v.toFixed(0)) } },
            colors: ['#556ee6', '#34c38f', '#f1b44c'],
            stroke: { width: [2, 2, 2], dashArray: [0, 4, 4] },
            fill: { type: ['gradient', 'solid', 'solid'], opacity: [0.3, 0, 0] },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
            dataLabels: { enabled: false },
        };

        if (trendChartObj) trendChartObj.destroy();
        trendChartObj = new ApexCharts(document.querySelector('#trend-chart'), opts);
        trendChartObj.render();

        // Anomalies
        if (d.anomalies && d.anomalies.length) {
            $('#anomaly-section').show();
            let html = '';
            d.anomalies.forEach(a => {
                html += `<div class="alert alert-warning py-2 mb-2"><strong>${a.date}</strong> — Revenue ₹${EcomUtils.number(a.revenue)}, expected ~₹${EcomUtils.number(a.expected)} (${a.deviation > 0 ? '+' : ''}${a.deviation.toFixed(1)}% deviation)</div>`;
            });
            $('#anomaly-list').html(html);
        }
    } catch (e) { console.error('Trend:', e); }
}

async function loadHourly() {
    try {
        const d = await fetchJson(`${API}/bi/intel/revenue/by-hour`);
        const hours = d.map(r => r.hour + ':00');
        const vals  = d.map(r => r.revenue);

        const opts = {
            chart: { type: 'bar', height: 320 },
            series: [{ name: 'Revenue', data: vals }],
            xaxis: { categories: hours },
            yaxis: { labels: { formatter: v => '₹' + (v >= 1000 ? (v/1000).toFixed(0) + 'K' : v.toFixed(0)) } },
            colors: ['#556ee6'],
            plotOptions: { bar: { borderRadius: 3 } },
            dataLabels: { enabled: false },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
        };

        if (hourlyChartObj) hourlyChartObj.destroy();
        hourlyChartObj = new ApexCharts(document.querySelector('#hourly-chart'), opts);
        hourlyChartObj.render();
    } catch (e) { console.error('Hourly:', e); }
}

async function loadBreakdown() {
    const dim = $('#breakdown-dim').val();
    $('#breakdown-label').text('by ' + dim.replace(/_/g, ' '));

    try {
        const d = await fetchJson(`${API}/bi/intel/revenue/breakdown?dimension=${dim}`);
        const labels = d.map(r => r.label || r._id || 'Unknown');
        const values = d.map(r => r.revenue);

        const opts = {
            chart: { type: 'donut', height: 340 },
            series: values,
            labels: labels,
            colors: ['#556ee6', '#34c38f', '#f1b44c', '#50a5f1', '#e83e8c', '#f46a6a', '#343a40', '#74788d'],
            legend: { position: 'bottom' },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
        };

        if (breakdownChartObj) breakdownChartObj.destroy();
        breakdownChartObj = new ApexCharts(document.querySelector('#breakdown-chart'), opts);
        breakdownChartObj.render();
    } catch (e) { console.error('Breakdown:', e); }
}

async function loadTopPerformers() {
    try {
        const d = await fetchJson(`${API}/bi/intel/revenue/top-performers?limit=10`);
        let html = '';
        (d.top_products || []).forEach((p, i) => {
            html += `<tr><td>${i+1}</td><td>${EcomUtils.truncate(p.name || p._id, 30)}</td><td class="text-end">₹${EcomUtils.number(p.revenue)}</td><td class="text-end">${p.qty}</td></tr>`;
        });
        $('#top-products-table tbody').html(html || '<tr><td colspan="4" class="text-muted text-center">No data</td></tr>');
    } catch (e) { console.error('Top performers:', e); }
}

$('#period-tabs .nav-link').on('click', function(e) {
    e.preventDefault();
    $('#period-tabs .nav-link').removeClass('active');
    $(this).addClass('active');
    if (window._ccData) renderKpis(window._ccData);
});

$('#breakdown-dim').on('change', loadBreakdown);

document.addEventListener('DOMContentLoaded', () => {
    loadCommandCenter();
    loadTrend();
    loadHourly();
    loadBreakdown();
    loadTopPerformers();
});
</script>
@endpush
