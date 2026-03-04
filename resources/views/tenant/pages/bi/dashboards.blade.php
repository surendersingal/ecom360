@extends('layouts.tenant')

@section('title', 'BI Dashboards')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">BI Dashboards</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Dashboards</li>
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
                        <h4 class="card-title mb-0">Your Dashboards</h4>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-dashboard"><i class="bx bx-plus me-1"></i> New Dashboard</button>
                    </div>
                    <div class="table-responsive">
                        <table id="dashboards-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Widgets</th>
                                    <th>Default</th>
                                    <th>Public</th>
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

    {{-- Create / Edit Modal --}}
    <div class="modal fade" id="dashboardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dashboardModalTitle">New Dashboard</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="dashboardForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="is_default" id="is_default">
                                    <label class="form-check-label" for="is_default">Default Dashboard</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="is_public" id="is_public">
                                    <label class="form-check-label" for="is_public">Public Access</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Dashboard</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/bi/dashboards';

    // DataTable
    const table = $('#dashboards-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function(xhr) {
                toastr.error('Failed to load dashboards');
                console.error('DataTable error', xhr);
            }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${EcomUtils.truncate(d, 30)}</strong>` },
            { data: 'description', render: (d) => EcomUtils.truncate(d || '—', 40), orderable: false },
            { data: 'widgets', render: (d) => `<span class="badge bg-soft-primary text-primary">${(d || []).length} widgets</span>`, orderable: false },
            { data: 'is_default', render: (d) => d ? '<i class="bx bx-check-circle text-success font-size-18"></i>' : '—', className: 'text-center' },
            { data: 'is_public', render: (d) => d ? '<i class="bx bx-globe text-info font-size-18"></i>' : '<i class="bx bx-lock-alt text-muted"></i>', className: 'text-center' },
            { data: 'updated_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'duplicate', id: id, label: 'Duplicate', icon: 'bx bx-copy' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[5, 'desc']],
        responsive: true,
        language: { emptyTable: 'No dashboards yet. Click "New Dashboard" to create one.' },
    });

    // New button
    $('#btn-new-dashboard').on('click', function() {
        EcomCRUD.resetForm('dashboardModal');
        $('#dashboardModalTitle').text('New Dashboard');
        $('#dashboardModal').modal('show');
    });

    // Form submit
    $('#dashboardForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'dashboardModal',
            formId: 'dashboardForm',
            apiBase: API_BASE,
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Action handler
    $(document).on('click', '#dashboards-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action');
        const id = $(this).data('id');
        const name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('dashboardModal');
                $('#dashboardModalTitle').text('Edit Dashboard');
                $('#dashboardForm [name="id"]').val(d._id || d.id);
                $('#dashboardForm [name="name"]').val(d.name);
                $('#dashboardForm [name="description"]').val(d.description);
                $('#dashboardForm [name="is_default"]').prop('checked', d.is_default);
                $('#dashboardForm [name="is_public"]').prop('checked', d.is_public);
                $('#dashboardModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }

        if (action === 'duplicate') {
            EcomAPI.post(`${API_BASE}/${id}/duplicate`).then(() => {
                toastr.success('Dashboard duplicated');
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
