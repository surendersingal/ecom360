@extends('layouts.tenant')
@section('title', 'CDP Dashboard')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-data" style="color:var(--analytics);"></i> Customer Data Platform</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">CDP</li>
                </ol>
            </nav>
        </div>
        <div>
            <button class="btn btn-sm btn-primary" id="btn-build-profiles" onclick="buildProfiles()">
                <i class="bx bx-refresh"></i> Rebuild All Profiles
            </button>
            <button class="btn btn-sm btn-outline-primary ms-2" id="btn-recalc-rfm" onclick="recalcRfm()">
                <i class="bx bx-calculator"></i> Recalculate RFM
            </button>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Profiles</span><span class="kpi-icon visitors"><i class="bx bx-group"></i></span></div>
                <div class="kpi-value" id="kpi-total">—</div><div class="kpi-sub" id="kpi-total-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">With Orders</span><span class="kpi-icon orders"><i class="bx bx-cart"></i></span></div>
                <div class="kpi-value" id="kpi-orders">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Profile Completeness</span><span class="kpi-icon conversion"><i class="bx bx-check-shield"></i></span></div>
                <div class="kpi-value" id="kpi-completeness">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Lifetime Value</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-ltv">—</div><div class="kpi-sub" id="kpi-ltv-sub"></div></div>
            </div>
        </div>

        {{-- RFM Distribution + Churn Risk --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-7">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">RFM Segment Distribution</h5>
                        <div id="rfm-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Churn Risk Overview</h5>
                        <div id="churn-chart" style="height:300px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Quick Stats Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-4 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Orders/Customer</span><span class="kpi-icon"><i class="bx bx-package" style="color:var(--chatbot);"></i></span></div>
                <div class="kpi-value" id="kpi-avg-orders">—</div></div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Order Value</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-aov">—</div></div>
            </div>
            <div class="col-xl-4 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Data Quality Issues</span><span class="kpi-icon" style="color:#dc3545;"><i class="bx bx-error-circle"></i></span></div>
                <div class="kpi-value" id="kpi-issues">—</div><div class="kpi-sub">Profiles with issues</div></div>
            </div>
        </div>

        {{-- RFM Segment Grid --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">RFM Segments — Quick Actions</h5>
                        <div id="rfm-grid" class="row g-3"></div>
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

async function loadDashboard() {
    try {
        const res = await fetch(`${API}/cdp/dashboard`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data;

        $('#kpi-total').text(EcomUtils.number(d.total_profiles));
        $('#kpi-total-sub').text(`${d.profiles_with_orders} with orders · ${d.anonymous_profiles} anonymous`);
        $('#kpi-orders').text(EcomUtils.number(d.profiles_with_orders));
        $('#kpi-completeness').text(`${d.avg_completeness}%`);
        $('#kpi-ltv').text('₹' + EcomUtils.number(d.total_ltv));
        $('#kpi-ltv-sub').text('Avg ₹' + EcomUtils.number(d.avg_ltv) + ' per customer');
        $('#kpi-avg-orders').text(d.avg_orders);
        $('#kpi-aov').text('₹' + EcomUtils.number(d.avg_aov));
        $('#kpi-issues').text(EcomUtils.number(d.quality_issues));

        // RFM chart
        if (d.rfm_distribution && d.rfm_distribution.length) {
            const labels = d.rfm_distribution.map(r => r.segment);
            const values = d.rfm_distribution.map(r => r.count);
            new ApexCharts(document.querySelector('#rfm-chart'), {
                chart: { type: 'bar', height: 340 },
                series: [{ name: 'Customers', data: values }],
                xaxis: { categories: labels },
                colors: ['#556ee6'],
                plotOptions: { bar: { borderRadius: 4 } },
                dataLabels: { enabled: true },
            }).render();
        } else {
            $('#rfm-chart').html('<div class="text-center text-muted py-5">No RFM data yet. Click "Rebuild All Profiles" then "Recalculate RFM".</div>');
        }

        // Churn chart
        if (d.churn_distribution && d.churn_distribution.length) {
            const labels = d.churn_distribution.map(r => r.level);
            const values = d.churn_distribution.map(r => r.count);
            const colors = labels.map(l => l === 'high' ? '#dc3545' : l === 'medium' ? '#ffc107' : '#28a745');
            new ApexCharts(document.querySelector('#churn-chart'), {
                chart: { type: 'donut', height: 300 },
                series: values,
                labels: labels.map(l => l.charAt(0).toUpperCase() + l.slice(1) + ' Risk'),
                colors: colors,
            }).render();
        } else {
            $('#churn-chart').html('<div class="text-center text-muted py-5">No churn data yet.</div>');
        }

        // RFM Grid
        if (d.rfm_distribution && d.rfm_distribution.length) {
            const icons = {'Champion':'🏆','Loyal':'💛','Potential Loyal':'🚀','New Customer':'🆕','Promising':'🔮','Need Attention':'💤','About to Sleep':'😴','At Risk':'⚠️','Cannot Lose':'❌','Hibernating':'👻'};
            const colors = {'Champion':'#28a745','Loyal':'#20c997','Potential Loyal':'#17a2b8','New Customer':'#007bff','Promising':'#6f42c1','Need Attention':'#ffc107','About to Sleep':'#fd7e14','At Risk':'#dc3545','Cannot Lose':'#e83e8c','Hibernating':'#6c757d'};
            let html = '';
            d.rfm_distribution.forEach(seg => {
                const pct = d.total_profiles > 0 ? ((seg.count / d.total_profiles) * 100).toFixed(1) : 0;
                const icon = icons[seg.segment] || '📊';
                const color = colors[seg.segment] || '#adb5bd';
                html += `<div class="col-xl-3 col-md-4 col-6">
                    <div class="card mb-0" style="border-left: 3px solid ${color};">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">${icon} ${seg.segment}</h6>
                                    <h4 class="mb-0">${EcomUtils.number(seg.count)}</h4>
                                    <small class="text-muted">${pct}% of customers</small>
                                </div>
                                <a href="{{ route('tenant.cdp.profiles', $tenant->slug) }}?rfm_segment=${encodeURIComponent(seg.segment)}" class="btn btn-sm btn-outline-primary">View</a>
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            $('#rfm-grid').html(html);
        }
    } catch (e) {
        console.error('CDP Dashboard error:', e);
        $('#kpi-total').text('Error');
    }
}

async function buildProfiles() {
    const btn = $('#btn-build-profiles');
    btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i> Building...');
    try {
        const res = await fetch(`${API}/cdp/profiles/build`, { method: 'POST', headers: {...headers, 'Content-Type': 'application/json'} });
        const json = await res.json();
        if (json.success) {
            alert(`Built ${json.data.built} profiles in ${json.data.duration_ms}ms (${json.data.errors} errors)`);
            loadDashboard();
        } else {
            alert('Error: ' + json.error);
        }
    } catch (e) { alert('Error: ' + e.message); }
    btn.prop('disabled', false).html('<i class="bx bx-refresh"></i> Rebuild All Profiles');
}

async function recalcRfm() {
    const btn = $('#btn-recalc-rfm');
    btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i> Computing...');
    try {
        const res = await fetch(`${API}/cdp/rfm/recalculate`, { method: 'POST', headers: {...headers, 'Content-Type': 'application/json'} });
        const json = await res.json();
        if (json.success) {
            alert(`Scored ${json.data.computed} profiles. ${json.data.no_orders} have no orders.`);
            loadDashboard();
        } else {
            alert('Error: ' + json.error);
        }
    } catch (e) { alert('Error: ' + e.message); }
    btn.prop('disabled', false).html('<i class="bx bx-calculator"></i> Recalculate RFM');
}

document.addEventListener('DOMContentLoaded', loadDashboard);
</script>
@endpush
