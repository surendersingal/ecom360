@extends('layouts.tenant')
@section('title', 'Segments')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-target-lock" style="color:var(--analytics);"></i> Audience Segments</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item active">Segments</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" onclick="openBuilder()"><i class="bx bx-plus"></i> New Segment</button>
    </div>

    <div class="e360-analytics-body">
        {{-- Segment List --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="seg-table">
                        <thead>
                            <tr><th>Name</th><th>Type</th><th>Members</th><th>Status</th><th>Marketing Sync</th><th>Last Evaluated</th><th></th></tr>
                        </thead>
                        <tbody id="seg-body">
                            <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Segment Builder Modal --}}
        <div class="modal fade" id="builderModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="builder-title">Create Segment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6"><label class="form-label">Name</label><input type="text" id="seg-name" class="form-control" placeholder="e.g. High-Value Repeat Buyers"></div>
                            <div class="col-md-6"><label class="form-label">Description</label><input type="text" id="seg-desc" class="form-control" placeholder="Optional description"></div>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <h6 class="mb-0">Conditions</h6>
                            <button class="btn btn-outline-secondary btn-sm" onclick="addGroup()"><i class="bx bx-plus"></i> Add Group (OR)</button>
                        </div>
                        <div id="condition-groups"></div>
                        <div class="mt-3 d-flex align-items-center gap-3">
                            <button class="btn btn-outline-primary" onclick="previewSegment()"><i class="bx bx-show"></i> Preview Count</button>
                            <span id="preview-result" class="text-muted"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btn-save" onclick="saveSegment()"><i class="bx bx-save"></i> Save Segment</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const SLUG = '{{ $tenant->slug }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' };

let dimensions = {};
let operators = [];
let editingId = null;

// ── Load dimensions & operators ──
async function loadMeta() {
    try {
        const res = await fetch(`${API}/cdp/dimensions`, { headers });
        const json = await res.json();
        if (json.success) {
            dimensions = json.data.dimensions || {};
            operators = json.data.operators || [];
        }
    } catch (e) { console.error('Meta load error', e); }
}

// ── Segment list ──
async function loadSegments() {
    try {
        const res = await fetch(`${API}/cdp/segments`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const segs = json.data || [];

        if (!segs.length) {
            $('#seg-body').html('<tr><td colspan="7" class="text-center text-muted py-4">No segments yet. Click "New Segment" to create one.</td></tr>');
            return;
        }

        let html = '';
        segs.forEach(s => {
            const typeColors = { dynamic: 'primary', static: 'secondary', rfm: 'success', predictive: 'info' };
            html += `<tr>
                <td><a href="{{ route('tenant.cdp.segments', $tenant->slug) }}/${s._id}" class="fw-semibold">${s.name}</a><br><small class="text-muted">${s.description || ''}</small></td>
                <td><span class="badge bg-${typeColors[s.type] || 'secondary'}">${s.type}</span></td>
                <td>${EcomUtils.number(s.member_count || 0)}</td>
                <td>${s.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
                <td>${s.synced_to_marketing ? '<i class="bx bx-check-circle text-success"></i>' : '<i class="bx bx-x text-muted"></i>'}</td>
                <td>${s.last_evaluated_at ? new Date(s.last_evaluated_at).toLocaleDateString('en-IN') : '—'}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="editSegment('${s._id}')"><i class="bx bx-edit"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteSegment('${s._id}')"><i class="bx bx-trash"></i></button>
                </td>
            </tr>`;
        });
        $('#seg-body').html(html);
    } catch (e) {
        console.error(e);
        $('#seg-body').html(`<tr><td colspan="7" class="text-center text-danger">Error: ${e.message}</td></tr>`);
    }
}

// ── Builder ──
let groupCounter = 0;

function openBuilder(seg = null) {
    editingId = seg ? seg._id : null;
    $('#builder-title').text(seg ? 'Edit Segment' : 'Create Segment');
    $('#seg-name').val(seg ? seg.name : '');
    $('#seg-desc').val(seg ? seg.description : '');
    $('#condition-groups').empty();
    $('#preview-result').text('');
    groupCounter = 0;

    if (seg && seg.conditions && seg.conditions.length) {
        seg.conditions.forEach(g => {
            addGroup();
            const gIdx = groupCounter - 1;
            (g.rules || []).forEach((r, rIdx) => {
                if (rIdx > 0) addRule(gIdx);
                const row = $(`#group-${gIdx} .rule-row`).last();
                row.find('.dim-select').val(r.dimension).trigger('change');
                setTimeout(() => {
                    row.find('.field-select').val(r.field);
                    row.find('.op-select').val(r.operator);
                    row.find('.val-input').val(r.value);
                }, 150);
            });
        });
    } else {
        addGroup();
    }
    new bootstrap.Modal('#builderModal').show();
}

function addGroup() {
    const g = groupCounter++;
    const html = `<div class="card mb-3 border" id="group-${g}">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold small">Group ${g + 1} <span class="badge bg-secondary ms-1">AND rules</span></span>
            <div>
                <button class="btn btn-sm btn-outline-secondary" onclick="addRule(${g})"><i class="bx bx-plus"></i> Add Rule</button>
                ${g > 0 ? `<button class="btn btn-sm btn-outline-danger ms-1" onclick="$('#group-${g}').remove()"><i class="bx bx-trash"></i></button>` : ''}
            </div>
        </div>
        <div class="card-body rules-container"></div>
    </div>`;
    $('#condition-groups').append(html);
    addRule(g);
}

function addRule(groupIdx) {
    const dimOptions = Object.keys(dimensions).map(d => `<option value="${d}">${d.charAt(0).toUpperCase() + d.slice(1)}</option>`).join('');
    const opOptions = operators.map(op => `<option value="${op.value}">${op.label}</option>`).join('');
    const html = `<div class="row g-2 mb-2 rule-row align-items-center">
        <div class="col-md-3">
            <select class="form-select form-select-sm dim-select" onchange="dimChanged(this)">
                <option value="">Dimension…</option>${dimOptions}
            </select>
        </div>
        <div class="col-md-3"><select class="form-select form-select-sm field-select"><option value="">Field…</option></select></div>
        <div class="col-md-2"><select class="form-select form-select-sm op-select">${opOptions}</select></div>
        <div class="col-md-3"><input type="text" class="form-control form-control-sm val-input" placeholder="Value"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-outline-danger" onclick="$(this).closest('.rule-row').remove()"><i class="bx bx-x"></i></button></div>
    </div>`;
    $(`#group-${groupIdx} .rules-container`).append(html);
}

function dimChanged(sel) {
    const dim = $(sel).val();
    const fieldSel = $(sel).closest('.rule-row').find('.field-select');
    fieldSel.html('<option value="">Field…</option>');
    if (dim && dimensions[dim]) {
        Object.entries(dimensions[dim]).forEach(([key, meta]) => {
            fieldSel.append(`<option value="${key}">${meta.label || key}</option>`);
        });
    }
}

function gatherConditions() {
    const groups = [];
    $('#condition-groups .card').each(function () {
        const rules = [];
        $(this).find('.rule-row').each(function () {
            const dim = $(this).find('.dim-select').val();
            const field = $(this).find('.field-select').val();
            const op = $(this).find('.op-select').val();
            const val = $(this).find('.val-input').val();
            if (dim && field && op) rules.push({ dimension: dim, field, operator: op, value: val });
        });
        if (rules.length) groups.push({ logic: 'AND', rules });
    });
    return groups;
}

async function previewSegment() {
    const conditions = gatherConditions();
    if (!conditions.length) { $('#preview-result').text('Add at least one rule.'); return; }
    $('#preview-result').text('Counting…');
    try {
        const res = await fetch(`${API}/cdp/segments/preview`, { method: 'POST', headers, body: JSON.stringify({ conditions }) });
        const json = await res.json();
        if (json.success) $('#preview-result').html(`<span class="badge bg-primary fs-6">${EcomUtils.number(json.data.count)} profiles match</span>`);
        else throw new Error(json.error);
    } catch (e) { $('#preview-result').text('Error: ' + e.message); }
}

async function saveSegment() {
    const conditions = gatherConditions();
    const body = { name: $('#seg-name').val(), description: $('#seg-desc').val(), type: 'dynamic', conditions };
    const url = editingId ? `${API}/cdp/segments/${editingId}` : `${API}/cdp/segments`;
    const method = editingId ? 'PUT' : 'POST';
    try {
        const res = await fetch(url, { method, headers, body: JSON.stringify(body) });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        bootstrap.Modal.getInstance('#builderModal').hide();
        loadSegments();
    } catch (e) { alert('Error saving: ' + e.message); }
}

async function editSegment(id) {
    try {
        const res = await fetch(`${API}/cdp/segments/${id}`, { headers });
        const json = await res.json();
        if (json.success) openBuilder(json.data);
    } catch (e) { alert('Error: ' + e.message); }
}

async function deleteSegment(id) {
    if (!confirm('Delete this segment?')) return;
    try {
        await fetch(`${API}/cdp/segments/${id}`, { method: 'DELETE', headers });
        loadSegments();
    } catch (e) { alert('Error: ' + e.message); }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadMeta();
    loadSegments();
});
</script>
@endpush
