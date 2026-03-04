@extends('layouts.tenant')

@section('title', 'Reports')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Reports</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Reports</li>
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
                        <h4 class="card-title mb-0">All Reports</h4>
                        <div>
                            <button type="button" class="btn btn-soft-primary btn-sm me-2" id="btn-from-template"><i class="bx bx-copy me-1"></i> From Template</button>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-new-report"><i class="bx bx-plus me-1"></i> Custom Report</button>
                        </div>
                    </div>

                    {{-- Template Gallery --}}
                    <div id="template-gallery" class="mb-4 d-none">
                        <h6 class="text-muted mb-3">Quick Start Templates</h6>
                        <div class="row" id="template-cards"></div>
                    </div>

                    <div class="table-responsive">
                        <table id="reports-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Schedule</th>
                                    <th>Last Run</th>
                                    <th>Status</th>
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
    <div class="modal fade" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reportModalTitle">New Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="reportForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="type" required>
                                    <option value="table">Table</option>
                                    <option value="chart">Chart</option>
                                    <option value="pivot">Pivot</option>
                                    <option value="summary">Summary</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Source</label>
                                <select class="form-select" name="config[data_source]">
                                    <option value="events">Events</option>
                                    <option value="customers">Customers</option>
                                    <option value="sessions">Sessions</option>
                                    <option value="campaigns">Campaigns</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Schedule</label>
                                <select class="form-select" name="schedule">
                                    <option value="">None (manual)</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Template Selection Modal --}}
    <div class="modal fade" id="templateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create from Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="template-list" class="list-group"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/bi/reports';
    const tplColors = { revenue_summary:'success', traffic_overview:'primary', product_performance:'warning', customer_insights:'info', campaign_roi:'danger', funnel_analysis:'secondary' };

    const table = $('#reports-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load reports'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${EcomUtils.truncate(d, 35)}</strong>` },
            { data: 'type', render: (d) => `<span class="badge bg-soft-primary text-primary">${d || '—'}</span>` },
            { data: 'schedule', render: (d) => d ? `<span class="badge bg-soft-info text-info">${d}</span>` : '<span class="text-muted">Manual</span>' },
            { data: 'last_run_at', render: (d) => EcomUtils.formatDate(d) },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'draft') },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'execute', id: id, label: 'Run Now', icon: 'bx bx-play text-success' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[3, 'desc']],
        responsive: true,
        language: { emptyTable: 'No reports yet. Create one to get started.' },
    });

    // New
    $('#btn-new-report').on('click', function() {
        EcomCRUD.resetForm('reportModal');
        $('#reportModalTitle').text('New Report');
        $('#reportModal').modal('show');
    });

    // From template
    $('#btn-from-template').on('click', function() {
        EcomAPI.get(API_BASE + '/meta/templates').then(json => {
            const templates = json.data || [];
            let html = '';
            templates.forEach(t => {
                html += `<a href="#" class="list-group-item list-group-item-action" data-template="${t.key || t.name}"><strong>${t.name}</strong><br><small class="text-muted">${t.description || ''}</small></a>`;
            });
            if (!html) html = '<p class="text-muted text-center py-3">No templates available</p>';
            $('#template-list').html(html);
            $('#templateModal').modal('show');
        }).catch(err => toastr.error(err.message));
    });

    $(document).on('click', '#template-list a', function(e) {
        e.preventDefault();
        const tpl = $(this).data('template');
        EcomAPI.post(API_BASE + '/from-template', { template: tpl }).then(() => {
            $('#templateModal').modal('hide');
            toastr.success('Report created from template');
            table.ajax.reload();
        }).catch(err => toastr.error(err.message));
    });

    // Form submit
    $('#reportForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'reportModal',
            formId: 'reportForm',
            apiBase: API_BASE,
            transform: function(data) {
                // Nest config fields
                const config = {};
                Object.keys(data).forEach(k => {
                    if (k.startsWith('config[')) {
                        config[k.replace('config[','').replace(']','')] = data[k];
                        delete data[k];
                    }
                });
                if (Object.keys(config).length) data.config = config;
                return data;
            },
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Actions
    $(document).on('click', '#reports-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action');
        const id = $(this).data('id');
        const name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('reportModal');
                $('#reportModalTitle').text('Edit Report');
                $('#reportForm [name="id"]').val(d._id || d.id);
                $('#reportForm [name="name"]').val(d.name);
                $('#reportForm [name="type"]').val(d.type);
                $('#reportForm [name="description"]').val(d.description);
                if (d.config) $('#reportForm [name="config[data_source]"]').val(d.config.data_source);
                $('#reportForm [name="schedule"]').val(d.schedule);
                $('#reportModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }

        if (action === 'execute') {
            toastr.info('Executing report…');
            EcomAPI.post(`${API_BASE}/${id}/execute`).then(json => {
                toastr.success('Report executed successfully');
                table.ajax.reload();
            }).catch(err => toastr.error(err.message));
        }

        if (action === 'delete') {
            EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
        }
    });
});
</script>
@endsection
