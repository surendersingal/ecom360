@extends('layouts.tenant')

@section('title', 'Real-Time Alerts')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Real-Time Alerts</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Monitoring</li>
                        <li class="breadcrumb-item active">Real-Time Alerts</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Pulse Overview --}}
    <div class="row" id="pulse-cards">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Active Now</p>
                            <h4 class="mb-0" id="pulse-active">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-success">
                            <span class="avatar-title"><i class="bx bx-user font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Events/Min</p>
                            <h4 class="mb-0" id="pulse-epm">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-info">
                            <span class="avatar-title"><i class="bx bx-pulse font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Open Alerts</p>
                            <h4 class="mb-0" id="pulse-open-alerts">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-danger">
                            <span class="avatar-title"><i class="bx bx-error-circle font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Health Score</p>
                            <h4 class="mb-0" id="pulse-health">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-warning">
                            <span class="avatar-title"><i class="bx bx-heart font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Alerts Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0"><i class="bx bx-error-circle text-danger me-1"></i> Recent Alerts</h4>
                        <div>
                            <button class="btn btn-sm btn-soft-primary me-1" id="btn-refresh-alerts"><i class="bx bx-refresh me-1"></i> Refresh</button>
                            <button class="btn btn-sm btn-soft-success" id="btn-refresh-pulse"><i class="bx bx-pulse me-1"></i> Pulse</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="alerts-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Alert</th>
                                    <th>Type</th>
                                    <th>Severity</th>
                                    <th>Value</th>
                                    <th>Threshold</th>
                                    <th>Status</th>
                                    <th>Triggered At</th>
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
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_ALERTS = '/analytics/advanced/alerts';
    const API_PULSE = '/analytics/advanced/pulse';

    function severityBadge(sev) {
        const s = (sev||'').toLowerCase();
        if (s === 'critical') return '<span class="badge bg-danger">Critical</span>';
        if (s === 'high' || s === 'warning') return '<span class="badge bg-warning">Warning</span>';
        if (s === 'medium') return '<span class="badge bg-info">Medium</span>';
        return '<span class="badge bg-secondary">' + (sev||'Low') + '</span>';
    }

    // Load pulse
    function loadPulse() {
        EcomAPI.get(API_PULSE).then(json => {
            const p = json.data || {};
            $('#pulse-active').text(EcomUtils.number(p.active_visitors || p.active_users || p.active_now || 0));
            $('#pulse-epm').text(EcomUtils.number(p.events_per_minute || p.epm || 0));
            $('#pulse-open-alerts').text(EcomUtils.number(p.open_alerts || p.active_alerts || 0));
            const health = p.health_score || p.health || null;
            if (health !== null) {
                const color = health >= 80 ? 'text-success' : health >= 50 ? 'text-warning' : 'text-danger';
                $('#pulse-health').html(`<span class="${color}">${health}%</span>`);
            }
        }).catch(() => {});
    }

    // Load alerts
    function loadAlerts() {
        EcomAPI.get(API_ALERTS).then(json => {
            const alerts = json.data?.alerts || json.data || [];
            const items = Array.isArray(alerts) ? alerts : [];
            const $tbody = $('#alerts-table tbody').empty();

            if (!items.length) {
                $tbody.append('<tr><td colspan="8" class="text-center text-muted py-4">No active alerts. Your metrics are looking healthy!</td></tr>');
                return;
            }

            items.forEach(a => {
                const id = a._id || a.id || a.alert_id;
                const acked = a.acknowledged || a.status === 'acknowledged';
                $tbody.append(`<tr class="${acked ? '' : 'table-soft-danger'}">
                    <td><strong>${a.name || a.message || a.title || '—'}</strong>${a.description ? '<br><small class="text-muted">'+a.description+'</small>' : ''}</td>
                    <td><span class="badge bg-soft-primary text-primary">${(a.type || a.alert_type || '—').replace(/_/g,' ')}</span></td>
                    <td>${severityBadge(a.severity || a.level)}</td>
                    <td class="fw-medium">${a.current_value !== undefined ? EcomUtils.number(a.current_value) : (a.value !== undefined ? EcomUtils.number(a.value) : '—')}</td>
                    <td>${a.threshold !== undefined ? EcomUtils.number(a.threshold) : '—'}</td>
                    <td>${acked ? '<span class="badge bg-success">Acknowledged</span>' : '<span class="badge bg-danger">Open</span>'}</td>
                    <td>${a.triggered_at || a.created_at ? EcomUtils.formatDate(a.triggered_at || a.created_at) : '—'}</td>
                    <td>${!acked ? `<button class="btn btn-sm btn-soft-success btn-ack" data-id="${id}"><i class="bx bx-check me-1"></i> Ack</button>` : '<span class="text-muted font-size-12">Done</span>'}</td>
                </tr>`);
            });
        }).catch(err => {
            toastr.error(err.message || 'Failed to load alerts');
        });
    }

    loadPulse();
    loadAlerts();

    $('#btn-refresh-alerts').on('click', loadAlerts);
    $('#btn-refresh-pulse').on('click', loadPulse);

    // Acknowledge
    $(document).on('click', '.btn-ack', function() {
        const $btn = $(this), id = $btn.data('id');
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i>');
        EcomAPI.post(`${API_ALERTS}/${id}/acknowledge`).then(() => {
            toastr.success('Alert acknowledged');
            loadAlerts();
            loadPulse();
        }).catch(err => {
            toastr.error(err.message || 'Failed to acknowledge');
            $btn.prop('disabled', false).html('<i class="bx bx-check me-1"></i> Ack');
        });
    });

    // Auto-refresh every 30 seconds
    setInterval(() => { loadPulse(); loadAlerts(); }, 30000);
});
</script>
@endsection
