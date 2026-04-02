@extends('layouts.tenant')
@section('title', 'Segment Detail')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-target-lock" style="color:var(--analytics);"></i> <span id="seg-name">Segment</span></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.segments', $tenant->slug) }}">Segments</a></li>
                    <li class="breadcrumb-item active">Detail</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="evaluateSegment()"><i class="bx bx-refresh"></i> Re-evaluate</button>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Segment Info --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-1" id="info-name">—</h5>
                        <p class="text-muted mb-1" id="info-desc">—</p>
                        <span class="badge bg-primary me-1" id="info-type">—</span>
                        <span class="badge" id="info-status">—</span>
                    </div>
                    <div class="col-auto text-end">
                        <div class="fs-3 fw-bold text-primary" id="info-count">0</div>
                        <small class="text-muted">Members</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            {{-- Conditions --}}
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Conditions</h6></div>
                    <div class="card-body" id="conditions-display">—</div>
                </div>
            </div>
            {{-- Trend Chart --}}
            <div class="col-lg-6">
                <div class="card h-100" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0">Member Trend</h6></div>
                    <div class="card-body"><div id="trend-chart" style="height:200px;"></div></div>
                </div>
            </div>
        </div>

        {{-- Overlap Analysis --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Overlap Analysis</h6>
                <div class="d-flex gap-2 align-items-center">
                    <select id="overlap-seg" class="form-select form-select-sm" style="width:250px;"><option value="">Compare with…</option></select>
                    <button class="btn btn-sm btn-outline-primary" onclick="runOverlap()">Compare</button>
                </div>
            </div>
            <div class="card-body" id="overlap-result" style="display:none;">
                <div class="row text-center">
                    <div class="col"><div class="fs-5 fw-bold" id="ov-a">0</div><small class="text-muted">This Segment Only</small></div>
                    <div class="col"><div class="fs-5 fw-bold text-primary" id="ov-both">0</div><small class="text-muted">Overlap</small></div>
                    <div class="col"><div class="fs-5 fw-bold" id="ov-b">0</div><small class="text-muted">Other Only</small></div>
                </div>
            </div>
        </div>

        {{-- Member Table --}}
        <div class="card" data-module="analytics">
            <div class="card-header"><h6 class="mb-0">Members</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>Customer</th><th>RFM</th><th>LTV</th><th>Churn</th><th>Completeness</th></tr></thead>
                        <tbody id="members-body"><tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-center mt-3" id="members-pag"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const SEG_ID = '{{ $segmentId ?? "" }}';
const SLUG = '{{ $tenant->slug }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' };

async function loadSegment() {
    try {
        const res = await fetch(`${API}/cdp/segments/${SEG_ID}`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const s = json.data;

        $('#seg-name').text(s.name);
        $('#info-name').text(s.name);
        $('#info-desc').text(s.description || '');
        $('#info-type').text(s.type);
        $('#info-status').text(s.is_active ? 'Active' : 'Inactive').addClass(s.is_active ? 'bg-success' : 'bg-secondary');
        $('#info-count').text(EcomUtils.number(s.member_count || 0));

        // Conditions display
        if (s.conditions && s.conditions.length) {
            let html = '';
            s.conditions.forEach((g, i) => {
                if (i > 0) html += '<div class="text-center my-2"><span class="badge bg-warning">OR</span></div>';
                html += '<div class="border rounded p-2 mb-1">';
                (g.rules || []).forEach((r, j) => {
                    if (j > 0) html += '<div class="small text-muted ms-3">AND</div>';
                    html += `<div class="small"><code>${r.dimension}.${r.field}</code> <b>${r.operator}</b> <code>${r.value}</code></div>`;
                });
                html += '</div>';
            });
            $('#conditions-display').html(html);
        }

        // Trend chart
        const trend = s.member_trend || [];
        if (trend.length > 1) {
            new ApexCharts(document.querySelector('#trend-chart'), {
                chart: { type: 'area', height: 200, toolbar: { show: false } },
                series: [{ name: 'Members', data: trend.map(t => t.count) }],
                xaxis: { categories: trend.map(t => t.date), labels: { show: false } },
                colors: ['#4361ee'],
                stroke: { curve: 'smooth', width: 2 },
                fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
            }).render();
        } else {
            $('#trend-chart').html('<div class="text-center text-muted py-4">Not enough data yet.</div>');
        }

        // Load other segments for overlap dropdown
        const allRes = await fetch(`${API}/cdp/segments`, { headers });
        const allJson = await allRes.json();
        if (allJson.success) {
            (allJson.data || []).filter(s2 => s2._id !== SEG_ID).forEach(s2 => {
                $('#overlap-seg').append(`<option value="${s2._id}">${s2.name} (${s2.member_count})</option>`);
            });
        }

    } catch (e) {
        console.error(e);
    }
}

async function loadMembers(page = 1) {
    try {
        const res = await fetch(`${API}/cdp/segments/${SEG_ID}/members?page=${page}&per_page=25`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const { members, total, page: pg, total_pages } = json.data;

        if (!members || !members.length) {
            $('#members-body').html('<tr><td colspan="5" class="text-center text-muted py-4">No members. Re-evaluate the segment.</td></tr>');
            return;
        }

        let html = '';
        members.forEach(m => {
            const d = m.demographics || {};
            const t = m.transactional || {};
            const c = m.computed || {};
            const name = [d.firstname, d.lastname].filter(Boolean).join(' ') || m.email;
            const riskColors = { high: 'danger', medium: 'warning', low: 'success' };
            html += `<tr style="cursor:pointer;" onclick="window.location='{{ route('tenant.cdp.profiles', $tenant->slug) }}/${m._id}'">
                <td><div class="fw-semibold">${name}</div><small class="text-muted">${m.email}</small></td>
                <td>${c.rfm_segment || '—'}</td>
                <td>₹${EcomUtils.number(t.lifetime_revenue || 0)}</td>
                <td><span class="badge bg-${riskColors[c.churn_risk_level] || 'secondary'}">${c.churn_risk_level || '—'}</span></td>
                <td>${m.profile_completeness || 0}%</td>
            </tr>`;
        });
        $('#members-body').html(html);

        let pagHtml = '';
        if (total_pages > 1) {
            pagHtml = '<nav><ul class="pagination pagination-sm mb-0">';
            for (let i = 1; i <= Math.min(total_pages, 10); i++) {
                pagHtml += `<li class="page-item ${i == pg ? 'active' : ''}"><a class="page-link" href="#" onclick="loadMembers(${i}); return false;">${i}</a></li>`;
            }
            pagHtml += '</ul></nav>';
        }
        $('#members-pag').html(pagHtml);
    } catch (e) {
        $('#members-body').html(`<tr><td colspan="5" class="text-center text-danger">${e.message}</td></tr>`);
    }
}

async function evaluateSegment() {
    try {
        const res = await fetch(`${API}/cdp/segments/${SEG_ID}/evaluate`, { method: 'POST', headers });
        const json = await res.json();
        if (json.success) {
            $('#info-count').text(EcomUtils.number(json.data.member_count || 0));
            loadMembers(1);
        }
    } catch (e) { alert('Error: ' + e.message); }
}

async function runOverlap() {
    const otherId = $('#overlap-seg').val();
    if (!otherId) return;
    try {
        const res = await fetch(`${API}/cdp/segments/overlap?segment_a=${SEG_ID}&segment_b=${otherId}`, { headers });
        const json = await res.json();
        if (json.success) {
            const d = json.data;
            $('#ov-a').text(EcomUtils.number(d.only_a));
            $('#ov-both').text(EcomUtils.number(d.overlap));
            $('#ov-b').text(EcomUtils.number(d.only_b));
            $('#overlap-result').show();
        }
    } catch (e) { alert('Error: ' + e.message); }
}

document.addEventListener('DOMContentLoaded', () => { loadSegment(); loadMembers(1); });
</script>
@endpush
