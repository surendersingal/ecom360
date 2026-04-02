@extends('layouts.tenant')
@section('title', 'RFM Analysis')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-grid-alt" style="color:var(--analytics);"></i> RFM Analysis</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item active">RFM</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" onclick="recalculate()"><i class="bx bx-refresh"></i> Recalculate RFM</button>
    </div>

    <div class="e360-analytics-body">
        {{-- KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">Total Scored</div>
                    <div class="fs-4 fw-bold" id="kpi-total">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">Champions</div>
                    <div class="fs-4 fw-bold text-success" id="kpi-champions">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">At Risk + Cannot Lose</div>
                    <div class="fs-4 fw-bold text-danger" id="kpi-atrisk">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">Total LTV</div>
                    <div class="fs-4 fw-bold" id="kpi-ltv">—</div>
                </div></div>
            </div>
        </div>

        {{-- RFM Distribution Chart --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div id="rfm-chart" style="height:350px;"></div>
            </div>
        </div>

        {{-- Revenue by Segment Chart --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div id="revenue-chart" style="height:300px;"></div>
            </div>
        </div>

        {{-- Segment Grid --}}
        <div class="card" data-module="analytics">
            <div class="card-header"><h6 class="mb-0">Segment Breakdown</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr><th>Segment</th><th>Profiles</th><th>% of Total</th><th>Revenue</th><th>Avg Orders</th><th>Avg AOV</th><th></th></tr>
                        </thead>
                        <tbody id="rfm-table-body">
                            <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
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
const SLUG = '{{ $tenant->slug }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' };

const segColors = {
    'Champion': '#2ecc71', 'Loyal': '#27ae60', 'Potential Loyal': '#3498db',
    'New Customer': '#1abc9c', 'Promising': '#9b59b6', 'Need Attention': '#f39c12',
    'About to Sleep': '#e67e22', 'At Risk': '#e74c3c', 'Cannot Lose': '#c0392b',
    'Hibernating': '#95a5a6'
};

const segIcons = {
    'Champion': '🏆', 'Loyal': '💛', 'Potential Loyal': '🚀', 'New Customer': '🆕',
    'Promising': '🔮', 'Need Attention': '💤', 'About to Sleep': '😴', 'At Risk': '⚠️',
    'Cannot Lose': '❌', 'Hibernating': '👻'
};

async function loadRfm() {
    try {
        const res = await fetch(`${API}/cdp/rfm`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const data = json.data || {};
        const segments = data.segments || [];

        const totalProfiles = segments.reduce((s, seg) => s + (seg.count || 0), 0);
        const totalRev = segments.reduce((s, seg) => s + (seg.total_revenue || 0), 0);
        const champions = segments.find(s => s.segment === 'Champion');
        const atRisk = segments.filter(s => ['At Risk', 'Cannot Lose'].includes(s.segment));
        const atRiskCount = atRisk.reduce((s, seg) => s + (seg.count || 0), 0);

        $('#kpi-total').text(EcomUtils.number(totalProfiles));
        $('#kpi-champions').text(EcomUtils.number(champions ? champions.count : 0));
        $('#kpi-atrisk').text(EcomUtils.number(atRiskCount));
        $('#kpi-ltv').text('₹' + EcomUtils.number(totalRev));

        // Distribution bar chart
        new ApexCharts(document.querySelector('#rfm-chart'), {
            chart: { type: 'bar', height: 350, toolbar: { show: false } },
            series: [{ name: 'Profiles', data: segments.map(s => s.count || 0) }],
            xaxis: { categories: segments.map(s => s.segment) },
            colors: segments.map(s => segColors[s.segment] || '#95a5a6'),
            plotOptions: { bar: { distributed: true, borderRadius: 4 } },
            legend: { show: false },
            title: { text: 'RFM Segment Distribution', align: 'left', style: { fontSize: '14px' } },
            dataLabels: { enabled: true, formatter: (v) => EcomUtils.number(v) },
        }).render();

        // Revenue chart
        new ApexCharts(document.querySelector('#revenue-chart'), {
            chart: { type: 'bar', height: 300, toolbar: { show: false } },
            series: [{ name: 'Revenue', data: segments.map(s => Math.round(s.total_revenue || 0)) }],
            xaxis: { categories: segments.map(s => s.segment) },
            colors: ['#4361ee'],
            plotOptions: { bar: { borderRadius: 4 } },
            title: { text: 'Revenue by Segment', align: 'left', style: { fontSize: '14px' } },
            dataLabels: { enabled: true, formatter: (v) => '₹' + EcomUtils.number(v) },
            yaxis: { labels: { formatter: v => '₹' + EcomUtils.number(v) } },
        }).render();

        // Table
        let html = '';
        segments.forEach(s => {
            const pct = totalProfiles ? ((s.count / totalProfiles) * 100).toFixed(1) : 0;
            html += `<tr>
                <td>${segIcons[s.segment] || '📊'} <b>${s.segment}</b></td>
                <td>${EcomUtils.number(s.count || 0)}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:6px;"><div class="progress-bar" style="width:${pct}%;background:${segColors[s.segment] || '#aaa'}"></div></div>
                        ${pct}%
                    </div>
                </td>
                <td>₹${EcomUtils.number(Math.round(s.total_revenue || 0))}</td>
                <td>${(s.avg_orders || 0).toFixed(1)}</td>
                <td>₹${EcomUtils.number(Math.round(s.avg_aov || 0))}</td>
                <td><a href="{{ route('tenant.cdp.profiles', $tenant->slug) }}?rfm_segment=${encodeURIComponent(s.segment)}" class="btn btn-sm btn-outline-primary">View</a></td>
            </tr>`;
        });
        if (!segments.length) html = '<tr><td colspan="7" class="text-center text-muted py-4">No RFM data. Build profiles and recalculate.</td></tr>';
        $('#rfm-table-body').html(html);

    } catch (e) {
        console.error(e);
        $('#rfm-table-body').html(`<tr><td colspan="7" class="text-center text-danger">${e.message}</td></tr>`);
    }
}

async function recalculate() {
    if (!confirm('Recalculate RFM scores for all profiles? This may take a moment.')) return;
    try {
        const res = await fetch(`${API}/cdp/rfm/recalculate`, { method: 'POST', headers });
        const json = await res.json();
        if (json.success) {
            alert(`Done! ${json.data.profiles_scored || 0} profiles scored.`);
            loadRfm();
        } else throw new Error(json.error);
    } catch (e) { alert('Error: ' + e.message); }
}

document.addEventListener('DOMContentLoaded', loadRfm);
</script>
@endpush
