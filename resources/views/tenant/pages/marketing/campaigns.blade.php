@extends('layouts.tenant')

@section('title', 'Campaigns')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Campaigns</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Campaigns</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row" id="campaign-stats">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Total Campaigns</p><h4 class="mb-0" id="stat-total">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center"><span class="avatar-title"><i class="bx bx-send font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Sent</p><h4 class="mb-0" id="stat-sent">0</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center"><span class="avatar-title"><i class="bx bx-check-circle font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Avg Open Rate</p><h4 class="mb-0" id="stat-open">0%</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center"><span class="avatar-title"><i class="bx bx-envelope-open font-size-24"></i></span></div></div></div></div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid"><div class="card-body"><div class="d-flex"><div class="flex-grow-1"><p class="text-muted fw-medium">Avg Click Rate</p><h4 class="mb-0" id="stat-click">0%</h4></div><div class="mini-stat-icon avatar-sm rounded-circle bg-info align-self-center"><span class="avatar-title"><i class="bx bx-pointer font-size-24"></i></span></div></div></div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">All Campaigns</h4>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-campaign"><i class="bx bx-plus me-1"></i> New Campaign</button>
                    </div>
                    <div class="table-responsive">
                        <table id="campaigns-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Channel</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Sent At</th>
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

    {{-- Campaign Modal --}}
    <div class="modal fade" id="campaignModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="campaignModalTitle">New Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="campaignForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Channel <span class="text-danger">*</span></label>
                                <select class="form-select" name="channel" required>
                                    <option value="email">Email</option>
                                    <option value="sms">SMS</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="rcs">RCS</option>
                                    <option value="push">Push</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="type" required>
                                    <option value="one_time">One-time</option>
                                    <option value="recurring">Recurring</option>
                                    <option value="triggered">Triggered</option>
                                    <option value="ab_test">A/B Test</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Template ID <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="template_id" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Audience Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="audience_type" required>
                                    <option value="all">All Contacts</option>
                                    <option value="list">By List</option>
                                    <option value="segment">By Segment</option>
                                    <option value="tags">By Tags</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Campaign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/marketing/campaigns';

    const table = $('#campaigns-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) {
                const items = json.data?.data || json.data || [];
                $('#stat-total').text(items.length);
                const sent = items.filter(i => i.status === 'sent' || i.status === 'completed');
                $('#stat-sent').text(sent.length);
                return items;
            },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load campaigns'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${EcomUtils.truncate(d, 35)}</strong>` },
            { data: 'channel', render: (d) => `<span class="badge bg-soft-primary text-primary">${d}</span>` },
            { data: 'type', render: (d) => (d||'').replace(/_/g, ' ') },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'draft') },
            { data: 'sent_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    const acts = [
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'stats', id: id, label: 'View Stats', icon: 'bx bx-bar-chart' },
                        { action: 'duplicate', id: id, label: 'Duplicate', icon: 'bx bx-copy' },
                    ];
                    if (row.status === 'draft') acts.push({ action: 'send', id: id, name: row.name, label: 'Send Now', icon: 'bx bx-send text-success' });
                    acts.push({ divider: true });
                    acts.push({ action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' });
                    return EcomUtils.actionDropdown(acts);
                }
            },
        ],
        order: [[4, 'desc']],
        responsive: true,
        language: { emptyTable: 'No campaigns yet. Create your first campaign.' },
    });

    $('#btn-new-campaign').on('click', function() {
        EcomCRUD.resetForm('campaignModal');
        $('#campaignModalTitle').text('New Campaign');
        $('#campaignModal').modal('show');
    });

    $('#campaignForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'campaignModal', formId: 'campaignForm', apiBase: API_BASE,
            transform: function(data) {
                data.audience = { type: data.audience_type };
                delete data.audience_type;
                return data;
            },
            onSuccess: () => table.ajax.reload(),
        });
    });

    $(document).on('click', '#campaigns-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('campaignModal');
                $('#campaignModalTitle').text('Edit Campaign');
                $('#campaignForm [name="id"]').val(d._id || d.id);
                $('#campaignForm [name="name"]').val(d.name);
                $('#campaignForm [name="channel"]').val(d.channel);
                $('#campaignForm [name="type"]').val(d.type);
                $('#campaignForm [name="template_id"]').val(d.template_id);
                $('#campaignForm [name="audience_type"]').val(d.audience?.type);
                $('#campaignModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'send') {
            Swal.fire({ title: 'Send "' + name + '"?', text: 'This will send the campaign to all recipients.', icon: 'question', showCancelButton: true, confirmButtonText: 'Send' }).then(r => {
                if (r.isConfirmed) {
                    EcomAPI.post(`${API_BASE}/${id}/send`).then(() => { toastr.success('Campaign sent!'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
                }
            });
        }
        if (action === 'stats') {
            EcomAPI.get(`${API_BASE}/${id}/stats`).then(json => {
                const s = json.data;
                Swal.fire({ title: 'Campaign Stats', html: `<div class="text-start"><p><strong>Sent:</strong> ${EcomUtils.number(s.sent)}</p><p><strong>Delivered:</strong> ${EcomUtils.number(s.delivered)}</p><p><strong>Opened:</strong> ${EcomUtils.number(s.opened)} (${EcomUtils.percent(s.open_rate)})</p><p><strong>Clicked:</strong> ${EcomUtils.number(s.clicked)} (${EcomUtils.percent(s.click_rate)})</p><p><strong>Bounced:</strong> ${EcomUtils.number(s.bounced)}</p><p><strong>Unsubscribed:</strong> ${EcomUtils.number(s.unsubscribed)}</p></div>`, icon: 'info' });
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'duplicate') {
            EcomAPI.post(`${API_BASE}/${id}/duplicate`).then(() => { toastr.success('Campaign duplicated'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
