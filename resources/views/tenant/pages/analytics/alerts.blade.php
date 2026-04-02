@extends('layouts.tenant')
@section('title', 'Real-Time Alerts')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-bell" style="color:var(--analytics);"></i> Alerts</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Alerts</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card" style="border-left-color:#DC2626;"><div class="kpi-header"><span class="kpi-label">Critical Alerts</span></div><div class="kpi-value" id="al-critical" style="color:#DC2626;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card" style="border-left-color:#D97706;"><div class="kpi-header"><span class="kpi-label">Warnings</span></div><div class="kpi-value" id="al-warning" style="color:#D97706;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Alerts (24h)</span></div><div class="kpi-value" id="al-total">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active Rules</span></div><div class="kpi-value" id="al-rules">—</div></div></div>
        </div>

        {{-- Active alerts --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Active Alerts</h5>
                        <div class="d-flex gap-2">
                            <select id="al-sev-filter" class="form-select form-select-sm" style="width:140px;">
                                <option value="">All Severities</option>
                                <option value="critical">Critical</option>
                                <option value="warning">Warning</option>
                                <option value="info">Info</option>
                            </select>
                            <button id="al-refresh" class="btn btn-sm btn-outline-primary"><i class="bx bx-refresh"></i></button>
                        </div>
                    </div>
                    <div id="al-list"><div class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i> Loading alerts...</div></div>
                </div></div>
            </div>
        </div>

        {{-- Alert rules --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Alert Rules</h5>
                        <button class="btn btn-sm btn-primary" id="al-create-rule"><i class="bx bx-plus me-1"></i> Create Rule</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Rule Name</th><th>Metric</th><th>Condition</th><th>Threshold</th><th>Status</th><th class="text-end">Last Triggered</th></tr></thead>
                            <tbody id="al-rules-body"><tr><td colspan="6" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
@endsection
@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection
@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    let allAlerts = [];

    function loadAlerts() {
        $.get(API + '/advanced/alerts').then(function(res) {
            const d = res?.data || res || {};
            const alerts = d.alerts || d.active_alerts || [];
            const rules = d.rules || d.alert_rules || [];

            allAlerts = alerts;
            const crit = alerts.filter(a => (a.severity||a.level||'').match(/critical|high/i));
            const warn = alerts.filter(a => (a.severity||a.level||'').match(/warning|medium/i));

            $('#al-critical').text(crit.length);
            $('#al-warning').text(warn.length);
            $('#al-total').text(alerts.length);
            $('#al-rules').text(rules.length);

            renderAlerts(alerts);
            renderRules(rules);
        }).catch(function() {
            $('#al-list').html('<div class="text-center py-3 text-muted">Unable to load alerts.</div>');
        });
    }

    function renderAlerts(alerts) {
        if (!alerts.length) {
            $('#al-list').html('<div class="text-center py-4"><i class="bx bx-check-circle text-success" style="font-size:32px;"></i><div class="mt-2 text-muted">No active alerts. Everything looks good!</div></div>');
            return;
        }
        let h = '';
        alerts.forEach(a => {
            const sev = (a.severity || a.level || 'info').toLowerCase();
            const sevConfig = {
                critical:{icon:'bx-error-circle',color:'#DC2626',bg:'#FEF2F2'},
                high:{icon:'bx-error-circle',color:'#DC2626',bg:'#FEF2F2'},
                warning:{icon:'bx-error',color:'#D97706',bg:'#FFFBEB'},
                medium:{icon:'bx-error',color:'#D97706',bg:'#FFFBEB'},
                info:{icon:'bx-info-circle',color:'#1A56DB',bg:'#EFF6FF'},
                low:{icon:'bx-info-circle',color:'#059669',bg:'#F0FDF4'}
            };
            const cfg = sevConfig[sev] || sevConfig.info;
            h += `<div class="d-flex align-items-start p-3 mb-2" style="background:${cfg.bg};border-radius:8px;border-left:3px solid ${cfg.color};">
                <i class="bx ${cfg.icon} me-2" style="color:${cfg.color};font-size:20px;margin-top:2px;"></i>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <span style="font-weight:600;font-size:13px;">${a.title||a.name||a.metric||'Alert'}</span>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="badge" style="background:${cfg.color};color:#fff;font-size:10px;">${sev.toUpperCase()}</span>
                            <span style="font-size:10px;color:#9CA3AF;">${a.triggered_at||a.created_at||a.time||''}</span>
                        </div>
                    </div>
                    <div style="font-size:12px;color:#6B7280;margin-top:2px;">${a.description||a.message||''}</div>
                    ${a.current_value != null ? `<div style="font-size:11px;margin-top:4px;"><span class="text-muted">Current:</span> <strong>${a.current_value}</strong> | <span class="text-muted">Threshold:</span> ${a.threshold||'—'}</div>` : ''}
                </div>
            </div>`;
        });
        $('#al-list').html(h);
    }

    function renderRules(rules) {
        if (!rules.length) { $('#al-rules-body').html('<tr><td colspan="6" class="text-center text-muted py-3">No alert rules configured yet.</td></tr>'); return; }
        let h = '';
        rules.forEach(r => {
            const active = r.active !== false;
            h += `<tr>
                <td style="font-size:12px;font-weight:500;">${r.name||r.title||'—'}</td>
                <td style="font-size:12px;">${r.metric||'—'}</td>
                <td style="font-size:12px;">${r.condition||r.operator||'>'}</td>
                <td style="font-size:12px;">${r.threshold||'—'}</td>
                <td><span class="badge bg-${active?'success':'secondary'}" style="font-size:10px;">${active?'Active':'Paused'}</span></td>
                <td class="text-end" style="font-size:11px;color:#9CA3AF;">${r.last_triggered||'Never'}</td>
            </tr>`;
        });
        $('#al-rules-body').html(h);
    }

    // Severity filter
    $('#al-sev-filter').on('change', function() {
        const f = this.value;
        renderAlerts(f ? allAlerts.filter(a => (a.severity||a.level||'').toLowerCase().includes(f)) : allAlerts);
    });

    // Refresh
    $('#al-refresh').on('click', loadAlerts);

    // Create rule modal placeholder
    $('#al-create-rule').on('click', function() {
        Swal.fire({
            title: 'Create Alert Rule',
            html: `
                <div class="text-start">
                    <div class="mb-3"><label class="form-label">Rule Name</label><input type="text" class="form-control" id="swal-rule-name" placeholder="e.g. High Bounce Rate"></div>
                    <div class="mb-3"><label class="form-label">Metric</label><select class="form-select" id="swal-rule-metric"><option>bounce_rate</option><option>conversion_rate</option><option>revenue</option><option>visitors</option><option>cart_abandonment</option></select></div>
                    <div class="mb-3"><label class="form-label">Condition</label><select class="form-select" id="swal-rule-cond"><option value=">">Greater than</option><option value="<">Less than</option><option value="=">Equals</option></select></div>
                    <div class="mb-3"><label class="form-label">Threshold</label><input type="number" class="form-control" id="swal-rule-threshold" placeholder="50"></div>
                </div>`,
            showCancelButton: true,
            confirmButtonText: 'Create Rule',
            preConfirm: () => ({
                name: $('#swal-rule-name').val(),
                metric: $('#swal-rule-metric').val(),
                condition: $('#swal-rule-cond').val(),
                threshold: $('#swal-rule-threshold').val()
            })
        }).then(result => {
            if (result.isConfirmed && result.value.name) {
                $.post(API + '/advanced/alerts/rules', result.value).then(()=>{
                    Swal.fire('Created!','Alert rule has been created.','success');
                    loadAlerts();
                }).catch(()=> Swal.fire('Error','Failed to create rule.','error'));
            }
        });
    });

    loadAlerts();
    setInterval(loadAlerts, 60000); // Auto-refresh every 60s
})();
</script>
@endsection
