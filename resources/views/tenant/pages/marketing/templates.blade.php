@extends('layouts.tenant')

@section('title', 'Templates')

@push('styles')
<style>
    .ck-editor__editable {
        min-height: 250px;
        max-height: 500px;
    }
    .ck.ck-editor {
        width: 100%;
    }
    .ck.ck-editor__main > .ck-editor__editable:not(.ck-focused) {
        border-color: #ced4da;
    }
    /* Make the modal wider for the editor */
    #templateModal .modal-dialog {
        max-width: 900px;
    }
</style>
@endpush

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Message Templates</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Templates</li>
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
                        <h4 class="card-title mb-0">All Templates</h4>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-template"><i class="bx bx-plus me-1"></i> New Template</button>
                    </div>

                    {{-- Channel filter tabs --}}
                    <ul class="nav nav-tabs nav-tabs-custom mb-4" role="tablist" id="channel-tabs">
                        <li class="nav-item"><a class="nav-link active" href="#" data-channel="">All</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-channel="email"><i class="bx bx-envelope me-1"></i> Email</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-channel="whatsapp"><i class="bx bxl-whatsapp me-1"></i> WhatsApp</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-channel="sms"><i class="bx bx-message-dots me-1"></i> SMS</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-channel="rcs"><i class="bx bx-message-square-detail me-1"></i> RCS</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-channel="push"><i class="bx bx-bell me-1"></i> Push</a></li>
                    </ul>

                    <div class="table-responsive">
                        <table id="templates-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Channel</th>
                                    <th>Subject / Preview</th>
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

    {{-- Template Modal --}}
    <div class="modal fade" id="templateModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalTitle">New Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="templateForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Channel <span class="text-danger">*</span></label>
                                <select class="form-select" name="channel" required>
                                    <option value="email">Email</option>
                                    <option value="sms">SMS</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="rcs">RCS</option>
                                    <option value="push">Push</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control" name="subject" placeholder="Email subject line">
                        </div>
                        <div class="mb-3" id="body-html-group">
                            <label class="form-label">Body (HTML)</label>
                            <div class="d-flex gap-2 mb-2">
                                <small class="text-muted">Use merge tags: <code>@{{first_name}}</code>, <code>@{{order_id}}</code>, <code>@{{unsubscribe_url}}</code></small>
                                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" id="btn-toggle-source" title="Toggle HTML source">
                                    <i class="bx bx-code-alt me-1"></i>Source
                                </button>
                            </div>
                            <textarea class="form-control" id="body_html_editor" name="body_html" rows="8"></textarea>
                            <textarea class="form-control font-monospace d-none" id="body_html_source" name="body_html_source" rows="10" style="font-size:12px;" placeholder="Paste raw HTML here..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Body (Plain Text) <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="body_text" rows="4" required placeholder="Plain-text fallback — use @{{first_name}} for dynamic content"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Template</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
<script>
$(function() {
    let editorInstance = null;
    let sourceMode = false;

    // Initialize CKEditor on the HTML textarea
    function initEditor() {
        if (editorInstance) return Promise.resolve(editorInstance);
        return ClassicEditor
            .create(document.querySelector('#body_html_editor'), {
                toolbar: {
                    items: [
                        'heading', '|',
                        'bold', 'italic', 'underline', 'strikethrough', '|',
                        'fontSize', 'fontColor', 'fontBackgroundColor', '|',
                        'alignment', '|',
                        'bulletedList', 'numberedList', 'outdent', 'indent', '|',
                        'link', 'insertImage', 'insertTable', 'blockQuote', 'horizontalLine', '|',
                        'undo', 'redo', '|',
                        'sourceEditing'
                    ],
                    shouldNotGroupWhenFull: true
                },
                image: {
                    toolbar: ['imageTextAlternative', 'imageStyle:inline', 'imageStyle:block', 'imageStyle:side']
                },
                table: {
                    contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells', 'tableProperties', 'tableCellProperties']
                },
                link: {
                    addTargetToExternalLinks: true,
                    defaultProtocol: 'https://'
                },
                placeholder: 'Design your email template here...'
            })
            .then(editor => {
                editorInstance = editor;
                // Sync CKEditor content to hidden textarea on every change
                editor.model.document.on('change:data', () => {
                    document.querySelector('#body_html_editor').value = editor.getData();
                });
                return editor;
            })
            .catch(err => {
                console.warn('CKEditor init failed, falling back to textarea:', err);
            });
    }

    function destroyEditor() {
        if (editorInstance) {
            editorInstance.destroy().catch(() => {});
            editorInstance = null;
        }
        sourceMode = false;
        $('#body_html_source').addClass('d-none');
        $('#body_html_editor').parent().find('.ck-editor').length || $('#body_html_editor').removeClass('d-none');
    }

    function setEditorContent(html) {
        if (editorInstance) {
            editorInstance.setData(html || '');
        } else {
            $('#body_html_editor').val(html || '');
        }
        $('#body_html_source').val(html || '');
    }

    function getEditorContent() {
        if (sourceMode) {
            return $('#body_html_source').val();
        }
        if (editorInstance) {
            return editorInstance.getData();
        }
        return $('#body_html_editor').val();
    }

    // Toggle between visual editor and source code view
    $('#btn-toggle-source').on('click', function() {
        sourceMode = !sourceMode;
        const $editorWrap = $('#body_html_editor').parent().find('.ck-editor');
        const $source = $('#body_html_source');
        const $btn = $(this);

        if (sourceMode) {
            // Switch to source
            $source.val(editorInstance ? editorInstance.getData() : $('#body_html_editor').val());
            $editorWrap.addClass('d-none');
            $source.removeClass('d-none').focus();
            $btn.addClass('btn-primary').removeClass('btn-outline-secondary');
        } else {
            // Switch back to visual
            const html = $source.val();
            if (editorInstance) editorInstance.setData(html);
            else $('#body_html_editor').val(html);
            $editorWrap.removeClass('d-none');
            $source.addClass('d-none');
            $btn.removeClass('btn-primary').addClass('btn-outline-secondary');
        }
    });

    // Sync source textarea back when typing
    $('#body_html_source').on('input', function() {
        $('#body_html_editor').val($(this).val());
    });
    const API_BASE = '/marketing/templates';
    let currentChannel = '';

    const table = $('#templates-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load templates'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${EcomUtils.truncate(d, 35)}</strong>` },
            { data: 'channel', render: (d) => `<span class="badge bg-soft-primary text-primary">${d}</span>` },
            { data: null, render: (r) => EcomUtils.truncate(r.subject || r.body_html || r.body_text || '', 50) },
            { data: 'updated_at', render: (d) => EcomUtils.formatDate(d) },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'preview', id: id, label: 'Preview', icon: 'bx bx-show' },
                        { action: 'duplicate', id: id, label: 'Duplicate', icon: 'bx bx-copy' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[3, 'desc']],
        responsive: true,
        language: { emptyTable: 'No templates yet. Create your first template.' },
    });

    // Channel tabs
    $('#channel-tabs a').on('click', function(e) {
        e.preventDefault();
        $('#channel-tabs a').removeClass('active');
        $(this).addClass('active');
        currentChannel = $(this).data('channel');
        table.column(1).search(currentChannel).draw();
    });

    $('#btn-new-template').on('click', function() {
        EcomCRUD.resetForm('templateModal');
        $('#templateModalTitle').text('New Template');
        setEditorContent('');
        $('#templateForm [name="channel"]').trigger('change');
        $('#templateModal').modal('show');
    });

    // Init CKEditor when modal opens with email channel
    $('#templateModal').on('shown.bs.modal', function() {
        const ch = $('#templateForm [name="channel"]').val();
        if (ch === 'email' && !editorInstance) {
            initEditor();
        }
    });
    // Clean up source mode on modal close
    $('#templateModal').on('hidden.bs.modal', function() {
        sourceMode = false;
        $('#body_html_source').addClass('d-none');
        $('#btn-toggle-source').removeClass('btn-primary').addClass('btn-outline-secondary');
        const $editorWrap = $('#body_html_editor').parent().find('.ck-editor');
        if ($editorWrap.length) $editorWrap.removeClass('d-none');
    });

    // Show/hide HTML body field based on channel (only email uses HTML)
    $('#templateForm [name="channel"]').on('change', function() {
        const ch = $(this).val();
        if (ch === 'email') {
            $('#body-html-group').show();
            if ($('#templateModal').hasClass('show') && !editorInstance) {
                initEditor();
            }
        } else {
            $('#body-html-group').hide();
            setEditorContent('');
        }
    });

    $('#templateForm').on('submit', function(e) {
        e.preventDefault();
        // Sync CKEditor content to the hidden textarea before submit
        const htmlContent = getEditorContent();
        $('#body_html_editor').val(htmlContent);
        EcomCRUD.submitForm({
            modalId: 'templateModal', formId: 'templateForm', apiBase: API_BASE,
            onSuccess: () => table.ajax.reload(),
            transform: (data) => {
                // Ensure body_html has the CKEditor content
                data.body_html = htmlContent;
                delete data.body_html_source;
                return data;
            },
        });
    });

    $(document).on('click', '#templates-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('templateModal');
                $('#templateModalTitle').text('Edit Template');
                $('#templateForm [name="id"]').val(d._id || d.id);
                $('#templateForm [name="name"]').val(d.name);
                $('#templateForm [name="channel"]').val(d.channel).trigger('change');
                $('#templateForm [name="subject"]').val(d.subject);
                setEditorContent(d.body_html);
                $('#templateForm [name="body_text"]').val(d.body_text);
                $('#templateModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'preview') {
            EcomAPI.get(`${API_BASE}/${id}/preview`).then(json => {
                const html = json.data?.html || json.data?.body || json.data?.preview || 'No preview available';
                Swal.fire({ title: 'Template Preview', html: `<div class="text-start border p-3" style="max-height:400px;overflow:auto">${html}</div>`, width: 600 });
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'duplicate') {
            EcomAPI.post(`${API_BASE}/${id}/duplicate`).then(() => { toastr.success('Template duplicated'); table.ajax.reload(); }).catch(err => toastr.error(err.message));
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
