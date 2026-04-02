@extends('layouts.tenant')
@section('title', 'Operations Intelligence')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-cog" style="color:var(--analytics);"></i> Operations Intelligence</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Operations</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Pipeline KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Orders</span><span class="kpi-icon orders"><i class="bx bx-package"></i></span></div>
                <div class="kpi-value" id="kpi-total-orders">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Value</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-total-value">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">At-Risk Orders</span><span class="kpi-icon"><i class="bx bx-error" style="color:#f46a6a;"></i></span></div>
                <div class="kpi-value" id="kpi-at-risk">—</div><div class="kpi-sub" id="kpi-at-risk-val"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">At-Risk Value</span><span class="kpi-icon"><i class="bx bx-shield-x" style="color:#fd7e14;"></i></span></div>
                <div class="kpi-value" id="kpi-risk-value">—</div></div>
            </div>
        </div>

        {{-- Pipeline + Daily Volume --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Order Pipeline by Status</h5>
                        <div id="pipeline-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Daily Order Volume (30 Days)</h5>
                        <div id="volume-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Payment Analysis --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-5">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Payment Methods</h5>
                        <div id="payment-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-7">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Payment Method Details</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" id="payment-table">
                                <thead><tr><th>Method</th><th class="text-end">Orders</th><th class="text-end">Revenue</th><th class="text-end">AOV</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Activity Heatmap --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Cross-Module Activity Heatmap</h5>
                        <ul class="nav nav-tabs mb-3" id="heatmap-tabs">
                            <li class="nav-item"><a class="nav-link active" data-source="orders" href="#">Orders</a></li>
                            <li class="nav-item"><a class="nav-link" data-source="events" href="#">Page Views</a></li>
                            <li class="nav-item"><a class="nav-link" data-source="search" href="#">Searches</a></li>
                        </ul>
                        <div id="heatmap-chart" style="height:300px;"></div>
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
let heatmapData = null, heatmapChart = null;

async function fetchJson(url) {
    const res = await fetch(url, { headers });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    return json.data;
}

async function loadPipeline() {
    try {
        const d = await fetchJson(`${API}/bi/intel/operations/pipeline`);
        $('#kpi-total-orders').text(EcomUtils.number(d.total_orders));
        $('#kpi-total-value').text('₹' + EcomUtils.number(d.total_value));
        $('#kpi-at-risk').text(EcomUtils.number(d.at_risk_count));
        $('#kpi-at-risk-val').text('pending/holded/fraud');
        $('#kpi-risk-value').text('₹' + EcomUtils.number(d.at_risk_value));

        const stages = d.stages || [];
        const statusColors = {
            'complete': '#28a745', 'processing': '#556ee6', 'pending': '#ffc107',
            'cancelled': '#f46a6a', 'canceled': '#f46a6a', 'closed': '#6c757d',
            'holded': '#fd7e14', 'fraud': '#dc3545',
        };

        new ApexCharts(document.querySelector('#pipeline-chart'), {
            chart: { type: 'bar', height: 340 },
            series: [{ name: 'Orders', data: stages.map(s => s.count) }],
            xaxis: { categories: stages.map(s => s.status) },
            colors: [function({ dataPointIndex }) {
                return statusColors[stages[dataPointIndex]?.status] || '#74788d';
            }],
            plotOptions: { bar: { distributed: true, borderRadius: 4 } },
            legend: { show: false },
            tooltip: { y: { formatter: (v, { dataPointIndex: i }) => `${v} orders · ₹${EcomUtils.number(stages[i]?.revenue || 0)}` } },
        }).render();
    } catch (e) { console.error('Pipeline:', e); }
}

async function loadVolume() {
    try {
        const d = await fetchJson(`${API}/bi/intel/operations/daily-volume`);
        new ApexCharts(document.querySelector('#volume-chart'), {
            chart: { type: 'area', height: 340 },
            series: [
                { name: 'Orders', data: d.map(r => r.orders) },
                { name: 'Revenue', data: d.map(r => r.revenue) },
            ],
            xaxis: { categories: d.map(r => r.date), tickAmount: 10 },
            yaxis: [
                { title: { text: 'Orders' } },
                { opposite: true, title: { text: 'Revenue' }, labels: { formatter: v => '₹' + (v >= 1000 ? (v/1000).toFixed(0) + 'K' : v.toFixed(0)) } },
            ],
            colors: ['#556ee6', '#34c38f'],
            stroke: { width: 2 },
            fill: { type: 'gradient', opacity: [0.3, 0.1] },
            dataLabels: { enabled: false },
        }).render();
    } catch (e) { console.error('Volume:', e); }
}

async function loadPayments() {
    try {
        const d = await fetchJson(`${API}/bi/intel/operations/payments`);
        new ApexCharts(document.querySelector('#payment-chart'), {
            chart: { type: 'donut', height: 320 },
            series: d.map(r => r.revenue),
            labels: d.map(r => r.method || 'Unknown'),
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
        }).render();

        let html = '';
        d.forEach(p => {
            html += `<tr><td>${p.method || 'Unknown'}</td><td class="text-end">${EcomUtils.number(p.orders)}</td><td class="text-end">₹${EcomUtils.number(p.revenue)}</td><td class="text-end">₹${EcomUtils.number(p.aov)}</td></tr>`;
        });
        $('#payment-table tbody').html(html || '<tr><td colspan="4" class="text-muted text-center">No data</td></tr>');
    } catch (e) { console.error('Payments:', e); }
}

async function loadHeatmap() {
    try {
        heatmapData = await fetchJson(`${API}/bi/intel/operations/heatmap`);
        renderHeatmap('orders');
    } catch (e) { console.error('Heatmap:', e); }
}

function renderHeatmap(source) {
    if (!heatmapData) return;
    const data = heatmapData[source] || [];
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Build series: one per day, data = 24 hours
    const series = days.map((dayName, dayIdx) => {
        const dayData = [];
        for (let h = 0; h < 24; h++) {
            const cell = data.find(c => c.day === (dayIdx + 1) && c.hour === h);
            dayData.push({ x: h + ':00', y: cell ? cell.value : 0 });
        }
        return { name: dayName, data: dayData };
    });

    const opts = {
        chart: { type: 'heatmap', height: 300 },
        series: series,
        colors: ['#556ee6'],
        dataLabels: { enabled: false },
        plotOptions: { heatmap: { radius: 2, colorScale: { ranges: [
            { from: 0, to: 0, color: '#f8f9fa' },
            { from: 1, to: 10, color: '#c3d7f7' },
            { from: 11, to: 50, color: '#7daef5' },
            { from: 51, to: 200, color: '#556ee6' },
            { from: 201, to: 99999, color: '#2a3eb1' },
        ]}}},
    };

    if (heatmapChart) heatmapChart.destroy();
    heatmapChart = new ApexCharts(document.querySelector('#heatmap-chart'), opts);
    heatmapChart.render();
}

$('#heatmap-tabs .nav-link').on('click', function(e) {
    e.preventDefault();
    $('#heatmap-tabs .nav-link').removeClass('active');
    $(this).addClass('active');
    renderHeatmap($(this).data('source'));
});

document.addEventListener('DOMContentLoaded', () => {
    loadPipeline();
    loadVolume();
    loadPayments();
    loadHeatmap();
});
</script>
@endpush
