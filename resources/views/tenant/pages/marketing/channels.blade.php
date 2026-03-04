@extends('layouts.tenant')

@section('title', 'Channels')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Channels</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Channels</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Channel Type Cards --}}
    <div class="row" id="channel-cards">
        @php
            $channelTypes = [
                ['type' => 'email', 'label' => 'Email', 'icon' => 'bx bx-envelope', 'color' => 'primary', 'desc' => 'SendGrid, Mailgun, SES, Postmark, SMTP'],
                ['type' => 'sms', 'label' => 'SMS', 'icon' => 'bx bx-message-dots', 'color' => 'success', 'desc' => 'Twilio, Vonage, MSG91'],
                ['type' => 'whatsapp', 'label' => 'WhatsApp', 'icon' => 'bx bxl-whatsapp', 'color' => 'success', 'desc' => 'Meta Cloud API, Twilio, Gupshup'],
                ['type' => 'rcs', 'label' => 'RCS', 'icon' => 'bx bx-message-square-detail', 'color' => 'info', 'desc' => 'Google RBM, Sinch, Infobip'],
                ['type' => 'push', 'label' => 'Push Notifications', 'icon' => 'bx bx-bell', 'color' => 'warning', 'desc' => 'Firebase FCM, OneSignal, Expo'],
            ];
        @endphp

        @foreach($channelTypes as $ch)
        <div class="col-xl-4 col-md-6">
            <div class="card" id="card-{{ $ch['type'] }}">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-sm me-3">
                            <span class="avatar-title rounded-circle bg-soft-{{ $ch['color'] }} text-{{ $ch['color'] }} font-size-20">
                                <i class="{{ $ch['icon'] }}"></i>
                            </span>
                        </div>
                        <div>
                            <h5 class="mb-0">{{ $ch['label'] }}</h5>
                            <p class="text-muted mb-0 font-size-12">{{ $ch['desc'] }}</p>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge bg-soft-secondary text-secondary channel-status" data-type="{{ $ch['type'] }}">Loading...</span>
                        <button class="btn btn-soft-primary btn-sm btn-configure" data-type="{{ $ch['type'] }}" data-label="{{ $ch['label'] }}"><i class="bx bx-cog me-1"></i> Configure</button>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Configured Channels Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Configured Channels</h4>
                    </div>
                    <div class="table-responsive">
                        <table id="channels-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Provider</th>
                                    <th>Active</th>
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

    {{-- Channel Config Modal --}}
    <div class="modal fade" id="channelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="channelModalTitle">Configure Channel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="channelForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <input type="hidden" name="type">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Provider <span class="text-danger">*</span></label>
                            <select class="form-select" name="provider" required id="provider-select"></select>
                        </div>
                        <div id="credential-fields">
                            <div class="mb-3">
                                <label class="form-label">API Key</label>
                                <input type="text" class="form-control" name="credentials[api_key]">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">API Secret</label>
                                <input type="password" class="form-control" name="credentials[api_secret]">
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="ch-active" checked>
                            <label class="form-check-label" for="ch-active">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-info btn-sm" id="btn-test-channel"><i class="bx bx-test-tube me-1"></i> Test Connection</button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save Channel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/marketing/channels';
    const providerMap = {
        email: ['smtp', 'sendgrid', 'mailgun', 'ses', 'postmark'],
        sms: ['twilio', 'vonage', 'msg91'],
        whatsapp: ['meta', 'twilio', 'gupshup'],
        rcs: ['google', 'sinch', 'infobip'],
        push: ['fcm', 'onesignal', 'expo'],
    };

    const table = $('#channels-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) {
                const items = json.data?.data || json.data || [];
                // Update status badges on channel cards
                const configured = {};
                items.forEach(i => { configured[i.type] = (configured[i.type]||0) + 1; });
                document.querySelectorAll('.channel-status').forEach(el => {
                    const type = el.dataset.type;
                    if (configured[type]) {
                        el.className = 'badge bg-success channel-status';
                        el.dataset.type = type;
                        el.textContent = configured[type] + ' configured';
                    } else {
                        el.className = 'badge bg-soft-secondary text-secondary channel-status';
                        el.dataset.type = type;
                        el.textContent = 'Not configured';
                    }
                });
                return items;
            },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load channels'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${d}</strong>` },
            { data: 'type', render: (d) => `<span class="badge bg-soft-primary text-primary">${d}</span>` },
            { data: 'provider', render: (d) => d || '—' },
            { data: 'is_active', render: (d) => d !== false ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { action: 'test', id: id, label: 'Test', icon: 'bx bx-test-tube text-info' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[1, 'asc']],
        responsive: true,
        language: { emptyTable: 'No channels configured. Click "Configure" on a channel type above.' },
    });

    function populateProviders(type) {
        const $sel = $('#provider-select').empty();
        (providerMap[type] || []).forEach(p => $sel.append(`<option value="${p}">${p.toUpperCase()}</option>`));
    }

    // Configure button on cards
    $('.btn-configure').on('click', function() {
        const type = $(this).data('type');
        const label = $(this).data('label');
        EcomCRUD.resetForm('channelModal');
        $('#channelModalTitle').text('Configure ' + label);
        $('#channelForm [name="type"]').val(type);
        $('#channelForm [name="name"]').val(label + ' Channel');
        populateProviders(type);
        $('#channelModal').modal('show');
    });

    // Form submit
    $('#channelForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'channelModal', formId: 'channelForm', apiBase: API_BASE,
            transform: function(data) {
                const credentials = {};
                Object.keys(data).forEach(k => {
                    if (k.startsWith('credentials[')) {
                        credentials[k.replace('credentials[','').replace(']','')] = data[k];
                        delete data[k];
                    }
                });
                if (Object.keys(credentials).length) data.credentials = credentials;
                return data;
            },
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Test button in modal
    $('#btn-test-channel').on('click', function() {
        const id = $('#channelForm [name="id"]').val();
        if (!id) { toastr.warning('Save the channel first before testing'); return; }
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Testing...');
        EcomAPI.post(`${API_BASE}/${id}/test`).then(json => {
            toastr.success('Connection test passed!');
        }).catch(err => toastr.error(err.message || 'Test failed'))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-test-tube me-1"></i> Test Connection'));
    });

    // Table Actions
    $(document).on('click', '#channels-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('channelModal');
                $('#channelModalTitle').text('Edit Channel');
                $('#channelForm [name="id"]').val(d._id || d.id);
                $('#channelForm [name="type"]').val(d.type);
                $('#channelForm [name="name"]').val(d.name);
                populateProviders(d.type);
                $('#channelForm [name="provider"]').val(d.provider);
                if (d.credentials) {
                    Object.entries(d.credentials).forEach(([k,v]) => { $(`#channelForm [name="credentials[${k}]"]`).val(v); });
                }
                $('#channelForm [name="is_active"]').prop('checked', d.is_active !== false);
                $('#channelModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'test') {
            EcomAPI.post(`${API_BASE}/${id}/test`).then(() => toastr.success('Test passed!')).catch(err => toastr.error(err.message));
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
