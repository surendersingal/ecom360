@extends('layouts.tenant')

@section('title', 'Contacts')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Contacts</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Contacts</li>
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
                        <h4 class="card-title mb-0">All Contacts</h4>
                        <div>
                            <button type="button" class="btn btn-soft-primary btn-sm me-2" id="btn-import"><i class="bx bx-import me-1"></i> Import</button>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-new-contact"><i class="bx bx-plus me-1"></i> Add Contact</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="contacts-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Tags</th>
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

    {{-- Contact Modal --}}
    <div class="modal fade" id="contactModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalTitle">Add Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="contactForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tags</label>
                            <input type="text" class="form-control" name="tags_str" placeholder="Comma-separated tags">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Import Modal --}}
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Import Contacts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Paste contacts as JSON array. Each object needs at least an <code>email</code> field.</p>
                    <textarea id="import-json" class="form-control font-monospace" rows="8" placeholder='[{"email":"john@example.com","first_name":"John"}]'></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btn-do-import">Import</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/marketing/contacts';

    const table = $('#contacts-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load contacts'); }
        },
        columns: [
            { data: null, render: (r) => `${r.first_name || ''} ${r.last_name || ''}`.trim() || '—' },
            { data: 'email' },
            { data: 'phone', render: (d) => d || '—' },
            { data: 'tags', render: (d) => (d||[]).map(t => `<span class="badge bg-soft-info text-info me-1">${t}</span>`).join('') || '—' },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'active') },
            { data: 'created_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'unsubscribe', id: id, name: row.email, label: 'Unsubscribe', icon: 'bx bx-user-minus text-warning' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.email, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[5, 'desc']],
        responsive: true,
        language: { emptyTable: 'No contacts yet. Import or add your first contact.' },
    });

    $('#btn-new-contact').on('click', function() {
        EcomCRUD.resetForm('contactModal');
        $('#contactModalTitle').text('Add Contact');
        $('#contactModal').modal('show');
    });

    $('#contactForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'contactModal', formId: 'contactForm', apiBase: API_BASE,
            transform: function(data) {
                if (data.tags_str) { data.tags = data.tags_str.split(',').map(t => t.trim()).filter(Boolean); }
                delete data.tags_str;
                return data;
            },
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Import
    $('#btn-import').on('click', function() { $('#import-json').val(''); $('#importModal').modal('show'); });
    $('#btn-do-import').on('click', function() {
        const $btn = $(this);
        try {
            const contacts = JSON.parse($('#import-json').val());
            $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Importing...');
            EcomAPI.post(API_BASE + '/bulk-import', { contacts }).then(json => {
                $('#importModal').modal('hide');
                toastr.success('Imported ' + (json.data?.imported || contacts.length) + ' contacts');
                table.ajax.reload();
            }).catch(err => toastr.error(err.message))
            .finally(() => $btn.prop('disabled', false).text('Import'));
        } catch(e) { toastr.error('Invalid JSON format'); }
    });

    // Actions
    $(document).on('click', '#contacts-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('contactModal');
                $('#contactModalTitle').text('Edit Contact');
                $('#contactForm [name="id"]').val(d._id || d.id);
                $('#contactForm [name="first_name"]').val(d.first_name);
                $('#contactForm [name="last_name"]').val(d.last_name);
                $('#contactForm [name="email"]').val(d.email);
                $('#contactForm [name="phone"]').val(d.phone);
                $('#contactForm [name="tags_str"]').val((d.tags||[]).join(', '));
                $('#contactModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }

        if (action === 'unsubscribe') {
            Swal.fire({ title: 'Unsubscribe ' + name + '?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Unsubscribe' }).then(r => {
                if (r.isConfirmed) {
                    EcomAPI.post(`${API_BASE}/${id}/unsubscribe`).then(() => { toastr.success('Contact unsubscribed'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
                }
            });
        }

        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
