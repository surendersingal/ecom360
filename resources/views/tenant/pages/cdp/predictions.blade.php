@extends('layouts.tenant')
@section('title', 'Predictive Intelligence')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-brain" style="color:var(--analytics);"></i> Predictive Intelligence</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item active">Predictions</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Predictive Segment Cards --}}
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-6">
                <div class="card h-100 border-success" data-module="analytics">
                    <div class="card-body text-center">
                        <div class="fs-1">🛒</div>
                        <h6 class="mt-2">Likely to Buy</h6>
                        <div class="fs-3 fw-bold text-success" id="pred-buy">—</div>
                        <small class="text-muted">Purchase propensity &gt; 70%</small>
                    </div>
                    <div class="card-footer text-center">
                        <a href="{{ route('tenant.cdp.profiles', $tenant->slug) }}?propensity_min=0.7" class="btn btn-sm btn-outline-success">View Profiles</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card h-100 border-danger" data-module="analytics">
                    <div class="card-body text-center">
                        <div class="fs-1">🚨</div>
                        <h6 class="mt-2">High Churn Risk</h6>
                        <div class="fs-3 fw-bold text-danger" id="pred-churn">—</div>
                        <small class="text-muted">Churn risk &gt; 70%</small>
                    </div>
                    <div class="card-footer text-center">
                        <a href="{{ route('tenant.cdp.profiles', $tenant->slug) }}?churn_risk=high" class="btn btn-sm btn-outline-danger">View Profiles</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card h-100 border-warning" data-module="analytics">
                    <div class="card-body text-center">
                        <div class="fs-1">🏷️</div>
                        <h6 class="mt-2">Discount Seekers</h6>
                        <div class="fs-3 fw-bold text-warning" id="pred-discount">—</div>
                        <small class="text-muted">Discount sensitivity &gt; 60%</small>
                    </div>
                    <div class="card-footer text-center">
                        <a href="#" class="btn btn-sm btn-outline-warning" onclick="alert('Filter coming soon'); return false;">View Profiles</a>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card h-100 border-primary" data-module="analytics">
                    <div class="card-body text-center">
                        <div class="fs-1">💎</div>
                        <h6 class="mt-2">Full Price Buyers</h6>
                        <div class="fs-3 fw-bold text-primary" id="pred-fullprice">—</div>
                        <small class="text-muted">Never used coupons</small>
                    </div>
                    <div class="card-footer text-center">
                        <a href="#" class="btn btn-sm btn-outline-primary" onclick="alert('Filter coming soon'); return false;">View Profiles</a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Distribution Charts --}}
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Purchase Propensity Distribution</h6></div>
                    <div class="card-body"><div id="propensity-chart" style="height:300px;"></div></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Churn Risk Distribution</h6></div>
                    <div class="card-body"><div id="churn-chart" style="height:300px;"></div></div>
                </div>
            </div>
        </div>

        {{-- LTV Distribution --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-header"><h6 class="mb-0">Predicted LTV Distribution</h6></div>
            <div class="card-body"><div id="ltv-chart" style="height:300px;"></div></div>
        </div>

        {{-- Top Predicted LTV Customers --}}
        <div class="card" data-module="analytics">
            <div class="card-header"><h6 class="mb-0">Top Predicted LTV Customers</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Customer</th><th>Predicted LTV</th><th>Current LTV</th><th>Propensity</th><th>Churn Risk</th><th>RFM</th></tr></thead>
                        <tbody id="top-ltv-body"><tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr></tbody>
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
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

async function loadPredictions() {
    try {
        const res = await fetch(`${API}/cdp/predictions`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data || {};

        // Segment counts
        $('#pred-buy').text(EcomUtils.number(d.likely_to_buy || 0));
        $('#pred-churn').text(EcomUtils.number(d.high_churn || 0));
        $('#pred-discount').text(EcomUtils.number(d.discount_seekers || 0));
        $('#pred-fullprice').text(EcomUtils.number(d.full_price_buyers || 0));

        // Propensity distribution
        const propBuckets = d.propensity_distribution || [];
        if (propBuckets.length) {
            new ApexCharts(document.querySelector('#propensity-chart'), {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Profiles', data: propBuckets.map(b => b.count) }],
                xaxis: { categories: propBuckets.map(b => b.range) },
                colors: ['#2ecc71'],
                plotOptions: { bar: { borderRadius: 4 } },
                dataLabels: { enabled: true },
            }).render();
        }

        // Churn distribution
        const churnBuckets = d.churn_distribution || [];
        if (churnBuckets.length) {
            new ApexCharts(document.querySelector('#churn-chart'), {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Profiles', data: churnBuckets.map(b => b.count) }],
                xaxis: { categories: churnBuckets.map(b => b.range) },
                colors: ['#e74c3c'],
                plotOptions: { bar: { borderRadius: 4 } },
                dataLabels: { enabled: true },
            }).render();
        }

        // LTV distribution
        const ltvBuckets = d.ltv_distribution || [];
        if (ltvBuckets.length) {
            new ApexCharts(document.querySelector('#ltv-chart'), {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Profiles', data: ltvBuckets.map(b => b.count) }],
                xaxis: { categories: ltvBuckets.map(b => b.range) },
                colors: ['#4361ee'],
                plotOptions: { bar: { borderRadius: 4 } },
                dataLabels: { enabled: true },
            }).render();
        }

        // Top LTV table
        const topLtv = d.top_ltv_customers || [];
        if (topLtv.length) {
            let html = '';
            topLtv.forEach(c => {
                const riskColors = { high: 'danger', medium: 'warning', low: 'success' };
                html += `<tr style="cursor:pointer;" onclick="window.location='{{ route('tenant.cdp.profiles', $tenant->slug) }}/${c._id}'">
                    <td><div class="fw-semibold">${c.name || c.email}</div><small class="text-muted">${c.email}</small></td>
                    <td class="fw-bold text-primary">₹${EcomUtils.number(Math.round(c.predicted_ltv || 0))}</td>
                    <td>₹${EcomUtils.number(Math.round(c.current_ltv || 0))}</td>
                    <td>${c.purchase_propensity ? (c.purchase_propensity * 100).toFixed(0) + '%' : '—'}</td>
                    <td><span class="badge bg-${riskColors[c.churn_risk_level] || 'secondary'}">${c.churn_risk_level || '—'}</span></td>
                    <td>${c.rfm_segment || '—'}</td>
                </tr>`;
            });
            $('#top-ltv-body').html(html);
        } else {
            $('#top-ltv-body').html('<tr><td colspan="6" class="text-center text-muted py-4">No prediction data yet. Build profiles and run RFM first.</td></tr>');
        }

    } catch (e) {
        console.error(e);
    }
}

document.addEventListener('DOMContentLoaded', loadPredictions);
</script>
@endpush
