@extends('layouts.tenant')
@section('title', 'Cohort Retention Analysis')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-grid-alt" style="color:var(--analytics);"></i> Cohort Retention Analysis</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Cohorts</li>
                </ol>
            </nav>
        </div>
        <div>
            <select id="months-range" class="form-select form-select-sm d-inline-block w-auto">
                <option value="3">Last 3 Months</option>
                <option value="6" selected>Last 6 Months</option>
                <option value="9">Last 9 Months</option>
                <option value="12">Last 12 Months</option>
            </select>
        </div>
    </div>

    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Cohort Retention Matrix</h5>
                        <p class="text-muted">Each row represents customers who made their first purchase in that month. Cells show the % who returned in subsequent months.</p>
                        <div class="table-responsive" id="cohort-table-wrap">
                            <div class="text-center py-5 text-muted"><i class="bx bx-loader-alt bx-spin font-size-24"></i><br>Loading cohort data...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Retention curve chart --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Retention Curve by Cohort</h5>
                        <div id="retention-chart" style="height:400px;"></div>
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

function retentionColor(pct) {
    if (pct >= 80) return '#28a745';
    if (pct >= 60) return '#34c38f';
    if (pct >= 40) return '#f1b44c';
    if (pct >= 20) return '#fd7e14';
    if (pct > 0)  return '#f46a6a';
    return '#f8f9fa';
}

async function loadCohorts() {
    const months = $('#months-range').val();
    try {
        const res = await fetch(`${API}/bi/intel/customers/cohort?months=${months}`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const data = json.data;

        if (!data || !data.length) {
            $('#cohort-table-wrap').html('<div class="text-center py-5 text-muted">No cohort data available</div>');
            return;
        }

        // Build table
        const maxPeriods = Math.max(...data.map(r => r.retention.length));
        let html = '<table class="table table-sm table-bordered mb-0 text-center" style="font-size:13px;">';
        html += '<thead><tr><th>Cohort</th><th>Size</th>';
        for (let i = 0; i < maxPeriods; i++) html += `<th>M+${i}</th>`;
        html += '</tr></thead><tbody>';

        data.forEach(row => {
            html += `<tr><td class="fw-bold text-start">${row.cohort}</td><td>${row.size}</td>`;
            row.retention.forEach(r => {
                const bg = retentionColor(r.rate);
                const fg = r.rate >= 40 ? '#fff' : '#333';
                html += `<td style="background:${bg};color:${fg};font-weight:600;" title="${r.active} of ${row.size}">${r.rate}%</td>`;
            });
            // Fill missing cells
            for (let i = row.retention.length; i < maxPeriods; i++) html += '<td></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        $('#cohort-table-wrap').html(html);

        // Retention curve chart
        const series = data.map(row => ({
            name: row.cohort,
            data: row.retention.map(r => r.rate),
        }));

        new ApexCharts(document.querySelector('#retention-chart'), {
            chart: { type: 'line', height: 400 },
            series: series,
            xaxis: { categories: data[0].retention.map(r => r.month) },
            yaxis: { max: 100, labels: { formatter: v => v.toFixed(0) + '%' } },
            stroke: { width: 2 },
            markers: { size: 4 },
            tooltip: { y: { formatter: v => v.toFixed(1) + '%' } },
        }).render();

    } catch (e) {
        console.error('Cohort:', e);
        $('#cohort-table-wrap').html('<div class="text-center py-5 text-danger">Error loading cohort data</div>');
    }
}

$('#months-range').on('change', loadCohorts);
document.addEventListener('DOMContentLoaded', loadCohorts);
</script>
@endpush
