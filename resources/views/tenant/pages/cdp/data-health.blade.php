@extends('layouts.tenant')
@section('title', 'Data Health')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-check-shield" style="color:var(--analytics);"></i> Data Health</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item active">Data Health</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">Total Profiles</div>
                    <div class="fs-4 fw-bold" id="kpi-total">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">Avg Completeness</div>
                    <div class="fs-4 fw-bold" id="kpi-avg">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100 border-danger" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">With Quality Issues</div>
                    <div class="fs-4 fw-bold text-danger" id="kpi-issues">—</div>
                </div></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center h-100 border-success" data-module="analytics"><div class="card-body">
                    <div class="small text-muted">100% Complete</div>
                    <div class="fs-4 fw-bold text-success" id="kpi-perfect">—</div>
                </div></div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            {{-- Completeness Distribution --}}
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Completeness Distribution</h6></div>
                    <div class="card-body"><div id="completeness-chart" style="height:300px;"></div></div>
                </div>
            </div>
            {{-- Missing Fields --}}
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Missing Fields</h6></div>
                    <div class="card-body"><div id="missing-chart" style="height:300px;"></div></div>
                </div>
            </div>
        </div>

        {{-- Quality Issues Table --}}
        <div class="card" data-module="analytics">
            <div class="card-header"><h6 class="mb-0">Quality Issues Breakdown</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Issue</th><th>Affected Profiles</th><th>% of Total</th><th>Severity</th></tr></thead>
                        <tbody id="issues-body"><tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr></tbody>
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

async function loadHealth() {
    try {
        const res = await fetch(`${API}/cdp/data-health`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data || {};

        $('#kpi-total').text(EcomUtils.number(d.total_profiles || 0));
        $('#kpi-avg').text((d.avg_completeness || 0).toFixed(1) + '%');
        $('#kpi-issues').text(EcomUtils.number(d.profiles_with_issues || 0));
        $('#kpi-perfect').text(EcomUtils.number(d.perfect_profiles || 0));

        // Completeness distribution
        const compDist = d.completeness_distribution || [];
        if (compDist.length) {
            new ApexCharts(document.querySelector('#completeness-chart'), {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Profiles', data: compDist.map(b => b.count) }],
                xaxis: { categories: compDist.map(b => b.range) },
                colors: ['#4361ee'],
                plotOptions: { bar: { borderRadius: 4 } },
                dataLabels: { enabled: true },
                title: { text: 'Profiles by Completeness %', align: 'left', style: { fontSize: '13px' } },
            }).render();
        }

        // Missing fields
        const missing = d.missing_fields || [];
        if (missing.length) {
            new ApexCharts(document.querySelector('#missing-chart'), {
                chart: { type: 'bar', height: 300, toolbar: { show: false } },
                series: [{ name: 'Missing', data: missing.map(m => m.count) }],
                xaxis: { categories: missing.map(m => m.field) },
                colors: ['#e74c3c'],
                plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                dataLabels: { enabled: true },
                title: { text: 'Most Common Missing Fields', align: 'left', style: { fontSize: '13px' } },
            }).render();
        }

        // Issues table
        const issues = d.quality_issues || [];
        if (issues.length) {
            const total = d.total_profiles || 1;
            const severityMap = {
                'missing_dob': 'low', 'missing_gender': 'low', 'missing_city': 'medium',
                'duplicate_phone': 'high', 'high_intent_anonymous': 'medium'
            };
            const severityColors = { low: 'secondary', medium: 'warning', high: 'danger' };
            let html = '';
            issues.forEach(iss => {
                const sev = severityMap[iss.issue] || 'secondary';
                const pct = ((iss.count / total) * 100).toFixed(1);
                html += `<tr>
                    <td>${iss.issue.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</td>
                    <td>${EcomUtils.number(iss.count)}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;"><div class="progress-bar bg-${severityColors[sev]}" style="width:${pct}%"></div></div>
                            ${pct}%
                        </div>
                    </td>
                    <td><span class="badge bg-${severityColors[sev]}">${sev}</span></td>
                </tr>`;
            });
            $('#issues-body').html(html);
        } else {
            $('#issues-body').html('<tr><td colspan="4" class="text-center text-success py-4"><i class="bx bx-check-circle"></i> No quality issues found!</td></tr>');
        }

    } catch (e) {
        console.error(e);
        $('#issues-body').html(`<tr><td colspan="4" class="text-center text-danger">${e.message}</td></tr>`);
    }
}

document.addEventListener('DOMContentLoaded', loadHealth);
</script>
@endpush
