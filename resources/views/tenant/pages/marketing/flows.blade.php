@extends('layouts.tenant')

@section('title', 'Automation Flows')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Automation Flows</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Flows</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row" id="flow-stats">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Active Flows</p><h4 class="mb-0" id="stat-active">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center"><span class="avatar-title"><i class="bx bx-git-branch font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Total Enrolled</p><h4 class="mb-0" id="stat-enrolled">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center"><span class="avatar-title"><i class="bx bx-user-plus font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Total Flows</p><h4 class="mb-0" id="stat-total">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-info align-self-center"><span class="avatar-title"><i class="bx bx-check-double font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Draft Flows</p><h4 class="mb-0" id="stat-draft">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center"><span class="avatar-title"><i class="bx bx-envelope font-size-24"></i></span></div></div></div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">All Flows</h4>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-flow"><i class="bx bx-plus me-1"></i> New Flow</button>
                    </div>
                    <div class="table-responsive">
                        <table id="flows-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Trigger</th>
                                    <th>Status</th>
                                    <th>Nodes</th>
                                    <th>Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Flow Modal --}}
    <div class="modal fade" id="flowModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="flowModalTitle">New Flow</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="flowForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Trigger Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="trigger_type" required>
                                <option value="event">Event</option>
                                <option value="segment_enter">Segment Enter</option>
                                <option value="segment_exit">Segment Exit</option>
                                <option value="date_field">Date Field</option>
                                <option value="manual">Manual</option>
                                <option value="schedule">Schedule</option>
                                <option value="webhook">Webhook</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Flow</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/marketing/flows';

    const table = $('#flows-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) {
                const items = json.data?.data || json.data || [];
                $('#stat-total').text(items.length);
                $('#stat-active').text(items.filter(f => f.status === 'active').length);
                $('#stat-draft').text(items.filter(f => f.status === 'draft' || !f.status).length);
                return items;
            },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load flows'); }
        },
        columns: [
            { data: null, render: function(row) {
                    const id = row._id || row.id;
                    const url = `{{ route('tenant.marketing.flows', $tenant->slug) }}/${id}/builder`;
                    return `<a href="${url}" class="fw-bold text-primary">${EcomUtils.truncate(row.name, 35)}</a>`;
                }
            },
            { data: 'trigger_type', render: (d) => `<span class="badge bg-soft-info text-info">${(d||'').replace(/_/g,' ')}</span>` },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'draft') },
            { data: 'nodes_count', render: (d) => (d || 0) + ' nodes', orderable: true, defaultContent: '0 nodes' },
            { data: 'updated_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    const acts = [
                        { action: 'builder', id: id, label: 'Open Builder', icon: 'bx bx-git-branch text-primary' },
                        { action: 'edit', id: id, label: 'Edit Settings', icon: 'bx bx-edit-alt' },
                    ];
                    if (row.status !== 'active') acts.push({ action: 'activate', id: id, name: row.name, label: 'Activate', icon: 'bx bx-play text-success' });
                    if (row.status === 'active') acts.push({ action: 'pause', id: id, name: row.name, label: 'Pause', icon: 'bx bx-pause text-warning' });
                    acts.push({ action: 'stats', id: id, label: 'Stats', icon: 'bx bx-bar-chart' });
                    acts.push({ divider: true });
                    acts.push({ action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' });
                    return EcomUtils.actionDropdown(acts);
                }
            },
        ],
        order: [[4, 'desc']],
        responsive: true,
        language: { emptyTable: 'No automation flows yet. Create your first flow.' },
    });

    $('#btn-new-flow').on('click', function() {
        EcomCRUD.resetForm('flowModal');
        $('#flowModalTitle').text('New Flow');
        $('#flowModal').modal('show');
    });

    $('#flowForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'flowModal', formId: 'flowForm', apiBase: API_BASE,
            onSuccess: (res) => {
                const newId = res?.data?.id || res?.data?._id;
                if (newId && !$('#flowForm [name="id"]').val()) {
                    // Redirect to builder for newly created flows
                    window.location.href = `{{ route('tenant.marketing.flows', $tenant->slug) }}/${newId}/builder`;
                } else {
                    table.ajax.reload();
                }
            },
        });
    });

    $(document).on('click', '#flows-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'builder') {
            window.location.href = `{{ route('tenant.marketing.flows', $tenant->slug) }}/${id}/builder`;
        }
        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('flowModal');
                $('#flowModalTitle').text('Edit Flow');
                $('#flowForm [name="id"]').val(d._id || d.id);
                $('#flowForm [name="name"]').val(d.name);
                $('#flowForm [name="description"]').val(d.description);
                $('#flowForm [name="trigger_type"]').val(d.trigger_type);
                $('#flowModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'activate') {
            EcomAPI.post(`${API_BASE}/${id}/activate`).then(() => { toastr.success('Flow activated'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
        }
        if (action === 'pause') {
            EcomAPI.post(`${API_BASE}/${id}/pause`).then(() => { toastr.success('Flow paused'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
        }
        if (action === 'stats') {
            EcomAPI.get(`${API_BASE}/${id}/stats`).then(json => {
                const s = json.data;
                Swal.fire({ title: 'Flow Stats', html: `<div class="text-start"><p><strong>Total Enrolled:</strong> ${EcomUtils.number(s.total_enrolled)}</p><p><strong>Active:</strong> ${EcomUtils.number(s.active_enrolled)}</p><p><strong>Conversion Rate:</strong> ${EcomUtils.percent(s.conversion_rate)}</p></div>`, icon: 'info' });
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
