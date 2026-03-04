@extends('layouts.tenant')

@section('title', 'BI Alerts')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">BI Alerts</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Alerts</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Alert Rules</h4>
                        <div>
                            <button type="button" class="btn btn-soft-warning btn-sm me-2" id="btn-evaluate-alerts"><i class="bx bx-analyse me-1"></i> Evaluate Now</button>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-new-alert"><i class="bx bx-plus me-1"></i> New Alert</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="alerts-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Condition</th>
                                    <th>Threshold</th>
                                    <th>Channels</th>
                                    <th>Status</th>
                                    <th>Last Triggered</th>
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

    {{-- Alert History Card --}}
    <div class="row d-none" id="history-section">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Alert History <small class="text-muted" id="history-alert-name"></small></h4>
                    <div class="table-responsive">
                        <table id="history-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Triggered At</th>
                                    <th>Value</th>
                                    <th>Message</th>
                                    <th>Acknowledged</th>
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

    {{-- Create / Edit Modal --}}
    <div class="modal fade" id="alertModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="alertModalTitle">New Alert</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="alertForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">KPI ID <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="kpi_id" required placeholder="Enter KPI ID">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Condition <span class="text-danger">*</span></label>
                                <select class="form-select" name="condition" required>
                                    <option value="above">Above Threshold</option>
                                    <option value="below">Below Threshold</option>
                                    <option value="change_percent">% Change</option>
                                    <option value="anomaly">Anomaly Detection</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Threshold <span class="text-danger">*</span></label>
                                <input type="number" step="any" class="form-control" name="threshold" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notification Channels <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="channels[]" value="email" id="ch-email" checked><label class="form-check-label" for="ch-email">Email</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="channels[]" value="slack" id="ch-slack"><label class="form-check-label" for="ch-slack">Slack</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="channels[]" value="sms" id="ch-sms"><label class="form-check-label" for="ch-sms">SMS</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="channels[]" value="webhook" id="ch-webhook"><label class="form-check-label" for="ch-webhook">Webhook</label></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Alert</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/bi/alerts';

    const table = $('#alerts-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load alerts'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${d}</strong>` },
            { data: 'condition', render: (d) => `<span class="badge bg-soft-info text-info">${d}</span>` },
            { data: 'threshold', render: (d) => EcomUtils.number(d) },
            { data: 'channels', render: (d) => (d || []).map(c => `<span class="badge bg-soft-primary text-primary me-1">${c}</span>`).join('') },
            { data: 'is_active', render: (d) => d !== false ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' },
            { data: 'last_triggered_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'history', id: id, name: row.name, label: 'View History', icon: 'bx bx-history' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[5, 'desc']],
        responsive: true,
        language: { emptyTable: 'No alerts configured. Create your first alert rule.' },
    });

    // New
    $('#btn-new-alert').on('click', function() {
        EcomCRUD.resetForm('alertModal');
        $('#alertModalTitle').text('New Alert');
        $('#alertModal').modal('show');
    });

    // Evaluate all
    $('#btn-evaluate-alerts').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Evaluating...');
        EcomAPI.post(API_BASE + '/evaluate').then(json => {
            toastr.success('Alerts evaluated: ' + (json.data?.triggered || 0) + ' triggered');
            table.ajax.reload();
        }).catch(err => toastr.error(err.message))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-analyse me-1"></i> Evaluate Now'));
    });

    // Form submit
    $('#alertForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'alertModal', formId: 'alertForm', apiBase: API_BASE,
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Actions
    $(document).on('click', '#alerts-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('alertModal');
                $('#alertModalTitle').text('Edit Alert');
                $('#alertForm [name="id"]').val(d._id || d.id);
                $('#alertForm [name="name"]').val(d.name);
                $('#alertForm [name="kpi_id"]').val(d.kpi_id);
                $('#alertForm [name="condition"]').val(d.condition);
                $('#alertForm [name="threshold"]').val(d.threshold);
                $('#alertForm [name="channels[]"]').prop('checked', false);
                (d.channels || []).forEach(c => $(`#alertForm [name="channels[]"][value="${c}"]`).prop('checked', true));
                $('#alertModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }

        if (action === 'history') {
            $('#history-section').removeClass('d-none');
            $('#history-alert-name').text('— ' + name);
            if ($.fn.DataTable.isDataTable('#history-table')) $('#history-table').DataTable().destroy();
            $('#history-table').DataTable({
                ajax: {
                    url: EcomAPI.baseUrl + `${API_BASE}/${id}/history`,
                    dataSrc: function(json) { return json.data?.data || json.data || []; },
                    beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
                },
                columns: [
                    { data: 'triggered_at', render: (d) => EcomUtils.formatDate(d) },
                    { data: 'value', render: (d) => EcomUtils.number(d) },
                    { data: 'message', render: (d) => EcomUtils.truncate(d, 60) },
                    { data: 'acknowledged_at', render: (d) => d ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-warning">No</span>' },
                    {
                        data: null, orderable: false,
                        render: (row) => !row.acknowledged_at ? `<button class="btn btn-sm btn-soft-success btn-ack" data-id="${row._id || row.id}"><i class="bx bx-check me-1"></i>Acknowledge</button>` : '—'
                    },
                ],
                order: [[0, 'desc']],
                responsive: true,
            });
        }

        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });

    // Acknowledge
    $(document).on('click', '.btn-ack', function() {
        const hid = $(this).data('id');
        EcomAPI.post(`${API_BASE}/history/${hid}/acknowledge`).then(() => {
            toastr.success('Alert acknowledged');
            $('#history-table').DataTable().ajax.reload();
        }).catch(err => toastr.error(err.message));
    });
});
</script>
@endsection
