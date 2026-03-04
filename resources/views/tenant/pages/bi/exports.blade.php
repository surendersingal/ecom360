@extends('layouts.tenant')

@section('title', 'Data Exports')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Data Exports</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Exports</li>
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
                        <h4 class="card-title mb-0">Export History</h4>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-export"><i class="bx bx-export me-1"></i> New Export</button>
                    </div>

                    <div class="row mb-4" id="format-stats">
                        <div class="col-md-3"><div class="border rounded p-3 text-center"><i class="bx bx-file text-success font-size-24 mb-2 d-block"></i><h6 class="mb-0">CSV</h6><small class="text-muted" id="count-csv">0 exports</small></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 text-center"><i class="bx bx-code-curly text-primary font-size-24 mb-2 d-block"></i><h6 class="mb-0">JSON</h6><small class="text-muted" id="count-json">0 exports</small></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 text-center"><i class="bx bx-spreadsheet text-info font-size-24 mb-2 d-block"></i><h6 class="mb-0">Excel</h6><small class="text-muted" id="count-xlsx">0 exports</small></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 text-center"><i class="bx bx-file-blank text-warning font-size-24 mb-2 d-block"></i><h6 class="mb-0">PDF</h6><small class="text-muted" id="count-pdf">0 exports</small></div></div>
                    </div>

                    <div class="table-responsive">
                        <table id="exports-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Format</th>
                                    <th>Size</th>
                                    <th>Status</th>
                                    <th>Created</th>
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

    {{-- New Export Modal --}}
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">New Export</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="exportForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Report <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="report_id" required placeholder="Report ID">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Format <span class="text-danger">*</span></label>
                            <select class="form-select" name="format" required>
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                                <option value="xlsx">Excel (XLSX)</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Create Export</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/bi/exports';

    const table = $('#exports-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) {
                const items = json.data?.data || json.data || [];
                // Update format counts
                const counts = { csv: 0, json: 0, xlsx: 0, pdf: 0 };
                items.forEach(i => { if (counts[i.format] !== undefined) counts[i.format]++; });
                Object.entries(counts).forEach(([k, v]) => $(`#count-${k}`).text(v + ' exports'));
                return items;
            },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load exports'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${EcomUtils.truncate(d, 35)}</strong>` },
            { data: 'format', render: (d) => `<span class="badge bg-soft-primary text-primary text-uppercase">${d}</span>` },
            { data: 'file_size', render: (d) => d ? (d > 1048576 ? (d/1048576).toFixed(1)+'MB' : (d/1024).toFixed(0)+'KB') : '—' },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'pending') },
            { data: 'created_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    const actions = [];
                    if (row.status === 'completed') actions.push({ action: 'download', id: id, label: 'Download', icon: 'bx bx-download text-success' });
                    actions.push({ divider: true });
                    actions.push({ action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' });
                    return EcomUtils.actionDropdown(actions);
                }
            },
        ],
        order: [[4, 'desc']],
        responsive: true,
        language: { emptyTable: 'No exports yet. Create your first data export.' },
    });

    $('#btn-new-export').on('click', function() {
        EcomCRUD.resetForm('exportModal');
        $('#exportModal').modal('show');
    });

    $('#exportForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'exportModal', formId: 'exportForm', apiBase: API_BASE,
            onSuccess: () => table.ajax.reload(),
        });
    });

    $(document).on('click', '#exports-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'download') {
            window.open(EcomAPI.baseUrl + `${API_BASE}/${id}/download`, '_blank');
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
