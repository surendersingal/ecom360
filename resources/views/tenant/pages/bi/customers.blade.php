@extends('layouts.tenant')
@section('title', 'Customer Intelligence')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-group" style="color:var(--analytics);"></i> Customer Intelligence</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Customers</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Customers</span><span class="kpi-icon visitors"><i class="bx bx-group"></i></span></div>
                <div class="kpi-value" id="kpi-total">—</div><div class="kpi-sub" id="kpi-total-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active (30d)</span><span class="kpi-icon orders"><i class="bx bx-user-check"></i></span></div>
                <div class="kpi-value" id="kpi-active">—</div><div class="kpi-sub" id="kpi-active-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Repeat Rate</span><span class="kpi-icon conversion"><i class="bx bx-refresh"></i></span></div>
                <div class="kpi-value" id="kpi-repeat">—</div><div class="kpi-sub">Customers with 2+ orders</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg LTV</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-ltv">—</div><div class="kpi-sub" id="kpi-ltv-sub"></div></div>
            </div>
        </div>

        {{-- Second KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">New This Month</span><span class="kpi-icon"><i class="bx bx-user-plus" style="color:#34c38f;"></i></span></div>
                <div class="kpi-value" id="kpi-new">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Orders/Customer</span><span class="kpi-icon"><i class="bx bx-package" style="color:#50a5f1;"></i></span></div>
                <div class="kpi-value" id="kpi-avg-orders">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Churn Rate (90d)</span><span class="kpi-icon"><i class="bx bx-log-out" style="color:#f46a6a;"></i></span></div>
                <div class="kpi-value" id="kpi-churn">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Revenue</span><span class="kpi-icon revenue"><i class="bx bx-wallet"></i></span></div>
                <div class="kpi-value" id="kpi-total-rev">—</div></div>
            </div>
        </div>

        {{-- Acquisition + Geo --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-7">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Customer Acquisition (Last 12 Months)</h5>
                        <div id="acquisition-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Top Cities by Revenue</h5>
                        <div id="geo-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- New vs Returning + Value Distribution --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">New vs Returning Customers</h5>
                        <div id="nvr-chart" style="height:320px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Customer Value Distribution</h5>
                        <div id="value-chart" style="height:320px;"></div>
                        <div class="text-center mt-2" id="value-summary"></div>
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

async function loadOverview() {
    try {
        const d = await fetchJson(`${API}/bi/intel/customers/overview`);
        $('#kpi-total').text(EcomUtils.number(d.total_customers));
        $('#kpi-total-sub').text(`${d.with_orders} with orders`);
        $('#kpi-active').text(EcomUtils.number(d.active_30d));
        $('#kpi-active-sub').text(`${d.active_30d_pct}% of customers`);
        $('#kpi-repeat').text(d.repeat_purchase_rate + '%');
        $('#kpi-ltv').text('₹' + EcomUtils.number(d.avg_ltv));
        $('#kpi-ltv-sub').text(`${d.avg_orders_per_cust} orders avg`);
        $('#kpi-new').text(EcomUtils.number(d.new_this_month));
        $('#kpi-avg-orders').text(d.avg_orders_per_cust);
        $('#kpi-churn').text(d.churn_rate_90d + '%');
        $('#kpi-total-rev').text('₹' + EcomUtils.number(d.total_revenue));
    } catch (e) { console.error('Overview:', e); }
}

async function loadAcquisition() {
    try {
        const d = await fetchJson(`${API}/bi/intel/customers/acquisition`);
        new ApexCharts(document.querySelector('#acquisition-chart'), {
            chart: { type: 'bar', height: 320 },
            series: [{ name: 'New Customers', data: d.map(r => r.new_customers) }],
            xaxis: { categories: d.map(r => r.month) },
            colors: ['#34c38f'],
            plotOptions: { bar: { borderRadius: 4 } },
            dataLabels: { enabled: true },
        }).render();
    } catch (e) { console.error('Acquisition:', e); }
}

async function loadGeo() {
    try {
        const d = await fetchJson(`${API}/bi/intel/customers/geo`);
        new ApexCharts(document.querySelector('#geo-chart'), {
            chart: { type: 'bar', height: 320 },
            series: [{ name: 'Revenue', data: d.map(r => r.revenue) }],
            xaxis: { categories: d.map(r => r.city) },
            colors: ['#556ee6'],
            plotOptions: { bar: { horizontal: true, borderRadius: 3 } },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
            dataLabels: { enabled: false },
        }).render();
    } catch (e) { console.error('Geo:', e); }
}

async function loadNvr() {
    try {
        const d = await fetchJson(`${API}/bi/intel/customers/new-vs-returning`);
        new ApexCharts(document.querySelector('#nvr-chart'), {
            chart: { type: 'bar', height: 320, stacked: true },
            series: [
                { name: 'New', data: d.map(r => r.new) },
                { name: 'Returning', data: d.map(r => r.returning) },
            ],
            xaxis: { categories: d.map(r => r.month) },
            colors: ['#34c38f', '#556ee6'],
            plotOptions: { bar: { borderRadius: 3 } },
        }).render();
    } catch (e) { console.error('NvR:', e); }
}

async function loadValue() {
    try {
        const d = await fetchJson(`${API}/bi/intel/customers/value-dist`);
        const dist = d.distribution || [];
        new ApexCharts(document.querySelector('#value-chart'), {
            chart: { type: 'bar', height: 320 },
            series: [{ name: 'Customers', data: dist.map(b => b.count) }],
            xaxis: { categories: dist.map(b => b.range) },
            colors: ['#50a5f1'],
            plotOptions: { bar: { borderRadius: 4 } },
            dataLabels: { enabled: true },
        }).render();
        $('#value-summary').html(`Avg LTV: <strong>₹${EcomUtils.number(d.avg_ltv)}</strong> · Median: <strong>₹${EcomUtils.number(d.median_ltv)}</strong> · Max: <strong>₹${EcomUtils.number(d.max_ltv)}</strong>`);
    } catch (e) { console.error('Value:', e); }
}

document.addEventListener('DOMContentLoaded', () => {
    loadOverview();
    loadAcquisition();
    loadGeo();
    loadNvr();
    loadValue();
});
</script>
@endpush
