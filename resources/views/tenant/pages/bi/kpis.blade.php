@extends('layouts.tenant')

@section('title', 'KPI Tracker')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">KPI Tracker</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">KPIs</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row" id="kpi-summary-cards"></div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Key Performance Indicators</h4>
                        <div>
                            <button type="button" class="btn btn-soft-info btn-sm me-2" id="btn-refresh-kpis"><i class="bx bx-refresh me-1"></i> Refresh All</button>
                            <button type="button" class="btn btn-soft-success btn-sm me-2" id="btn-load-defaults"><i class="bx bx-reset me-1"></i> Load Defaults</button>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-new-kpi"><i class="bx bx-plus me-1"></i> Add KPI</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="kpis-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Metric</th>
                                    <th>Current</th>
                                    <th>Target</th>
                                    <th>Progress</th>
                                    <th>Trend</th>
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
    <div class="modal fade" id="kpiModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kpiModalTitle">Add KPI</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="kpiForm">
                    <div class="modal-body">
                        <input type="hidden" name="id">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Metric <span class="text-danger">*</span></label>
                            <select class="form-select" name="metric" required>
                                <option value="revenue">Revenue</option>
                                <option value="orders">Orders</option>
                                <option value="aov">Average Order Value</option>
                                <option value="conversion_rate">Conversion Rate</option>
                                <option value="sessions">Sessions</option>
                                <option value="bounce_rate">Bounce Rate</option>
                                <option value="cart_abandonment">Cart Abandonment</option>
                                <option value="customers">New Customers</option>
                                <option value="repeat_rate">Repeat Purchase Rate</option>
                                <option value="clv">Customer Lifetime Value</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Target Value</label>
                                <input type="number" step="any" class="form-control" name="target">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit</label>
                                <select class="form-select" name="unit">
                                    <option value="">Number</option>
                                    <option value="currency">Currency ($)</option>
                                    <option value="percent">Percentage (%)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save">Save KPI</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_BASE = '/bi/kpis';

    const table = $('#kpis-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE,
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load KPIs'); }
        },
        columns: [
            { data: 'name', render: (d) => `<strong>${d}</strong>` },
            { data: 'metric', render: (d) => `<code class="font-size-12">${d || '—'}</code>` },
            { data: 'current_value', render: (d) => EcomUtils.number(d) },
            { data: 'target', render: (d) => d ? EcomUtils.number(d) : '—' },
            {
                data: null, orderable: false,
                render: function(row) {
                    if (!row.target) return '—';
                    const pct = Math.min(100, ((row.current_value || 0) / row.target) * 100).toFixed(0);
                    const color = pct >= 80 ? 'success' : pct >= 50 ? 'warning' : 'danger';
                    return `<div class="progress" style="height:8px;width:80px;"><div class="progress-bar bg-${color}" style="width:${pct}%"></div></div><small>${pct}%</small>`;
                }
            },
            {
                data: 'trend', orderable: false,
                render: function(d) {
                    if (!d) return '—';
                    const icon = d > 0 ? 'bx-trending-up text-success' : d < 0 ? 'bx-trending-down text-danger' : 'bx-minus text-muted';
                    return `<i class="bx ${icon} font-size-18"></i> <small>${d > 0 ? '+' : ''}${d}%</small>`;
                }
            },
            {
                data: null, orderable: false, className: 'text-center',
                render: function(row) {
                    const id = row._id || row.id;
                    return EcomUtils.actionDropdown([
                        { action: 'edit', id: id, label: 'Edit', icon: 'bx bx-edit-alt' },
                        { divider: true },
                        { action: 'delete', id: id, name: row.name, label: 'Delete', icon: 'bx bx-trash text-danger', class: 'text-danger' },
                    ]);
                }
            },
        ],
        order: [[0, 'asc']],
        responsive: true,
        language: { emptyTable: 'No KPIs configured. Load defaults or add your own.' },
    });

    // New
    $('#btn-new-kpi').on('click', function() {
        EcomCRUD.resetForm('kpiModal');
        $('#kpiModalTitle').text('Add KPI');
        $('#kpiModal').modal('show');
    });

    // Load defaults
    $('#btn-load-defaults').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Loading...');
        EcomAPI.post(API_BASE + '/defaults').then(() => {
            toastr.success('Default KPIs loaded');
            table.ajax.reload();
        }).catch(err => toastr.error(err.message))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-reset me-1"></i> Load Defaults'));
    });

    // Refresh all
    $('#btn-refresh-kpis').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Refreshing...');
        EcomAPI.post(API_BASE + '/refresh').then(() => {
            toastr.success('KPIs refreshed');
            table.ajax.reload();
        }).catch(err => toastr.error(err.message))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-refresh me-1"></i> Refresh All'));
    });

    // Form submit
    $('#kpiForm').on('submit', function(e) {
        e.preventDefault();
        EcomCRUD.submitForm({
            modalId: 'kpiModal', formId: 'kpiForm', apiBase: API_BASE,
            onSuccess: () => table.ajax.reload(),
        });
    });

    // Actions
    $(document).on('click', '#kpis-table [data-action]', function(e) {
        e.preventDefault();
        const action = $(this).data('action'), id = $(this).data('id'), name = $(this).data('name');

        if (action === 'edit') {
            EcomAPI.get(`${API_BASE}/${id}`).then(json => {
                const d = json.data;
                EcomCRUD.resetForm('kpiModal');
                $('#kpiModalTitle').text('Edit KPI');
                $('#kpiForm [name="id"]').val(d._id || d.id);
                $('#kpiForm [name="name"]').val(d.name);
                $('#kpiForm [name="metric"]').val(d.metric);
                $('#kpiForm [name="target"]').val(d.target);
                $('#kpiForm [name="unit"]').val(d.unit);
                $('#kpiModal').modal('show');
            }).catch(err => toastr.error(err.message));
        }
        if (action === 'delete') EcomCRUD.confirmDelete(`${API_BASE}/${id}`, name, () => table.ajax.reload());
    });
});
</script>
@endsection
