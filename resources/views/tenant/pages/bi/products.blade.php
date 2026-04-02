@extends('layouts.tenant')
@section('title', 'Product Intelligence')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-store-alt" style="color:var(--analytics);"></i> Product Intelligence</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Products</li>
                </ol>
            </nav>
        </div>
        <div>
            <select id="sort-by" class="form-select form-select-sm d-inline-block w-auto">
                <option value="revenue">Sort by Revenue</option>
                <option value="qty">Sort by Quantity</option>
                <option value="growth">Sort by Growth</option>
                <option value="aov">Sort by AOV</option>
            </select>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Rising & Falling Stars --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card border-success" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title text-success"><i class="bx bx-trending-up"></i> Rising Stars</h5>
                        <div id="rising-stars" class="table-responsive">
                            <table class="table table-sm mb-0"><thead><tr><th>Product</th><th class="text-end">Revenue</th><th class="text-end">Growth</th></tr></thead><tbody></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card border-danger" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title text-danger"><i class="bx bx-trending-down"></i> Falling Stars</h5>
                        <div id="falling-stars" class="table-responsive">
                            <table class="table table-sm mb-0"><thead><tr><th>Product</th><th class="text-end">Revenue</th><th class="text-end">Growth</th></tr></thead><tbody></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Leaderboard --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Product Leaderboard</h5>
                        <div class="table-responsive">
                            <table class="table table-nowrap mb-0" id="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Previous</th>
                                        <th class="text-end">Growth</th>
                                        <th class="text-center">Trend</th>
                                        <th class="text-end">Units</th>
                                        <th class="text-end">AOV</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Category BCG Matrix + Pareto --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Category BCG Matrix</h5>
                        <div id="bcg-chart" style="height:380px;"></div>
                        <div class="mt-2 d-flex gap-3 flex-wrap justify-content-center">
                            <span class="badge bg-success">Stars ⭐</span>
                            <span class="badge bg-primary">Question Marks ❓</span>
                            <span class="badge bg-warning text-dark">Cash Cows 🐄</span>
                            <span class="badge bg-secondary">Dogs 🐕</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Pareto (80/20) Analysis</h5>
                        <div id="pareto-chart" style="height:340px;"></div>
                        <div class="text-center mt-2" id="pareto-summary"></div>
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

async function fetchJson(url) {
    const res = await fetch(url, { headers });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'API error');
    return json.data;
}

async function loadLeaderboard() {
    const sortBy = $('#sort-by').val();
    try {
        const d = await fetchJson(`${API}/bi/intel/products/leaderboard?sort=${sortBy}&limit=25`);
        let html = '';
        d.forEach((p, i) => {
            const growthCls = p.growth >= 0 ? 'text-success' : 'text-danger';
            html += `<tr>
                <td>${i+1}</td>
                <td>${EcomUtils.truncate(p.name || p._id, 35)}</td>
                <td class="text-end">₹${EcomUtils.number(p.revenue)}</td>
                <td class="text-end text-muted">₹${EcomUtils.number(p.prev_revenue || 0)}</td>
                <td class="text-end ${growthCls}">${p.growth >= 0 ? '+' : ''}${(p.growth || 0).toFixed(1)}%</td>
                <td class="text-center">${p.trend || '→'}</td>
                <td class="text-end">${p.qty}</td>
                <td class="text-end">₹${EcomUtils.number(p.aov || 0)}</td>
            </tr>`;
        });
        $('#leaderboard-table tbody').html(html || '<tr><td colspan="8" class="text-muted text-center">No product data</td></tr>');
    } catch (e) { console.error('Leaderboard:', e); }
}

async function loadStars() {
    try {
        const d = await fetchJson(`${API}/bi/intel/products/stars`);

        let html = '';
        (d.rising || []).forEach(p => {
            html += `<tr><td>${EcomUtils.truncate(p.name || p._id, 28)}</td><td class="text-end">₹${EcomUtils.number(p.revenue)}</td><td class="text-end text-success">+${p.growth.toFixed(1)}%</td></tr>`;
        });
        $('#rising-stars tbody').html(html || '<tr><td colspan="3" class="text-muted text-center">None</td></tr>');

        html = '';
        (d.falling || []).forEach(p => {
            html += `<tr><td>${EcomUtils.truncate(p.name || p._id, 28)}</td><td class="text-end">₹${EcomUtils.number(p.revenue)}</td><td class="text-end text-danger">${p.growth.toFixed(1)}%</td></tr>`;
        });
        $('#falling-stars tbody').html(html || '<tr><td colspan="3" class="text-muted text-center">None</td></tr>');
    } catch (e) { console.error('Stars:', e); }
}

async function loadBCG() {
    try {
        const d = await fetchJson(`${API}/bi/intel/products/category-matrix`);
        const quadColors = { star: '#28a745', question_mark: '#007bff', cash_cow: '#ffc107', dog: '#6c757d' };
        const series = [];

        Object.entries(quadColors).forEach(([q, color]) => {
            const items = d.filter(c => c.quadrant === q);
            if (items.length) {
                series.push({
                    name: q.replace(/_/g, ' '),
                    data: items.map(c => ({
                        x: c.revenue_share,
                        y: c.growth,
                        z: c.revenue,
                        label: c.category
                    }))
                });
            }
        });

        new ApexCharts(document.querySelector('#bcg-chart'), {
            chart: { type: 'bubble', height: 380, toolbar: { show: false } },
            series: series,
            xaxis: { title: { text: 'Revenue Share (%)' }, min: 0 },
            yaxis: { title: { text: 'Growth Rate (%)' } },
            colors: Object.values(quadColors),
            tooltip: {
                custom: function({ seriesIndex, dataPointIndex, w }) {
                    const pt = w.config.series[seriesIndex].data[dataPointIndex];
                    return `<div class="p-2"><strong>${pt.label}</strong><br>Share: ${pt.x.toFixed(1)}%<br>Growth: ${pt.y.toFixed(1)}%<br>Revenue: ₹${EcomUtils.number(pt.z)}</div>`;
                }
            },
            dataLabels: { enabled: false },
        }).render();
    } catch (e) { console.error('BCG:', e); }
}

async function loadPareto() {
    try {
        const d = await fetchJson(`${API}/bi/intel/products/pareto`);
        const cumData = d.cumulative_curve || [];
        const pcts = cumData.map((_, i) => ((i + 1) / cumData.length * 100).toFixed(0) + '%');
        const cumRevPct = cumData.map(r => r.cumulative_pct);

        new ApexCharts(document.querySelector('#pareto-chart'), {
            chart: { type: 'area', height: 340 },
            series: [{ name: 'Cumulative Revenue %', data: cumRevPct }],
            xaxis: { categories: pcts, title: { text: '% of Products' }, tickAmount: 10 },
            yaxis: { max: 100, labels: { formatter: v => v.toFixed(0) + '%' } },
            colors: ['#556ee6'],
            annotations: {
                yaxis: [{ y: 80, borderColor: '#f46a6a', strokeDashArray: 4, label: { text: '80% Revenue', style: { color: '#f46a6a' } } }],
                xaxis: [{ x: '20%', borderColor: '#f46a6a', strokeDashArray: 4, label: { text: '20% Products' } }],
            },
            fill: { type: 'gradient', opacity: 0.3 },
            dataLabels: { enabled: false },
            tooltip: { y: { formatter: v => v.toFixed(1) + '%' } },
        }).render();

        if (d.top20_product_count !== undefined) {
            $('#pareto-summary').html(`<span class="text-primary fw-bold">${d.top20_product_count} products</span> (top 20%) generate <span class="text-success fw-bold">${d.top20_revenue_pct?.toFixed(1)}%</span> of total revenue`);
        }
    } catch (e) { console.error('Pareto:', e); }
}

$('#sort-by').on('change', loadLeaderboard);

document.addEventListener('DOMContentLoaded', () => {
    loadLeaderboard();
    loadStars();
    loadBCG();
    loadPareto();
});
</script>
@endpush
