@extends('layouts.tenant')

@section('title', 'Customer Lifetime Value')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Customer Lifetime Value</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Analytics</li>
                        <li class="breadcrumb-item active">Customer Lifetime Value</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row" id="clv-summary">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Avg CLV</p>
                            <h4 class="mb-0" id="stat-avg-clv">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-primary">
                            <span class="avatar-title"><i class="bx bx-trophy font-size-24"></i></span>
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
                            <p class="text-muted fw-medium mb-2">Max CLV</p>
                            <h4 class="mb-0" id="stat-max-clv">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-success">
                            <span class="avatar-title"><i class="bx bx-trending-up font-size-24"></i></span>
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
                            <p class="text-muted fw-medium mb-2">Total Customers</p>
                            <h4 class="mb-0" id="stat-total-customers">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-info">
                            <span class="avatar-title"><i class="bx bx-group font-size-24"></i></span>
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
                            <p class="text-muted fw-medium mb-2">Median CLV</p>
                            <h4 class="mb-0" id="stat-med-clv">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-warning">
                            <span class="avatar-title"><i class="bx bx-bar-chart font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- CLV Table --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">CLV Predictions</h4>
                        <button class="btn btn-sm btn-soft-primary" id="btn-refresh-clv"><i class="bx bx-refresh me-1"></i> Refresh</button>
                    </div>
                    <div class="table-responsive">
                        <table id="clv-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Visitor ID</th>
                                    <th>Predicted CLV</th>
                                    <th>Probability Alive</th>
                                    <th>Frequency</th>
                                    <th>Recency</th>
                                    <th>Monetary</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- What-If Scenario --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4"><i class="bx bx-test-tube me-1"></i> What-If Scenario</h4>
                    <form id="whatif-form">
                        <div class="mb-3">
                            <label class="form-label">Visitor ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="visitor_id" required placeholder="e.g. visitor_abc123">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Additional Purchases</label>
                            <input type="number" class="form-control" name="scenario[additional_purchases]" value="5" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Avg Order Increase ($)</label>
                            <input type="number" class="form-control" name="scenario[aov_increase]" value="10" step="0.01">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Time Horizon (days)</label>
                            <input type="number" class="form-control" name="scenario[days_horizon]" value="365" min="1">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bx bx-play me-1"></i> Run Scenario</button>
                    </form>
                    <div id="whatif-result" class="mt-4" style="display:none;">
                        <h5 class="text-center">Scenario Result</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody id="whatif-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API = '/analytics/advanced/clv';

    function loadCLV() {
        EcomAPI.get(API + '?limit=100').then(json => {
            const data = json.data || {};
            const customers = data.predictions || data.customers || (Array.isArray(data) ? data : []);

            // Update summary
            if (customers.length) {
                const clvs = customers.map(c => c.predicted_clv || c.clv || 0);
                const sorted = [...clvs].sort((a,b) => a-b);
                $('#stat-avg-clv').text('$' + EcomUtils.number(clvs.reduce((a,b) => a+b, 0) / clvs.length));
                $('#stat-max-clv').text('$' + EcomUtils.number(Math.max(...clvs)));
                $('#stat-total-customers').text(EcomUtils.number(customers.length));
                $('#stat-med-clv').text('$' + EcomUtils.number(sorted[Math.floor(sorted.length/2)]));
            }

            // Populate table
            const $tbody = $('#clv-table tbody').empty();
            customers.forEach(c => {
                $tbody.append(`<tr>
                    <td><code>${c.visitor_id || c.customer_id || '—'}</code></td>
                    <td><strong class="text-success">$${EcomUtils.number(c.predicted_clv || c.clv || 0)}</strong></td>
                    <td>${EcomUtils.percent(c.probability_alive || c.p_alive || 0)}</td>
                    <td>${c.frequency ?? '—'}</td>
                    <td>${c.recency ?? '—'} days</td>
                    <td>$${EcomUtils.number(c.monetary || c.avg_order_value || 0)}</td>
                </tr>`);
            });

            if (!customers.length) {
                $tbody.append('<tr><td colspan="6" class="text-center text-muted py-4">No CLV data available. Ensure events have been ingested.</td></tr>');
            }
        }).catch(err => toastr.error(err.message || 'Failed to load CLV data'));
    }

    loadCLV();
    $('#btn-refresh-clv').on('click', loadCLV);

    // What-If
    $('#whatif-form').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]').prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Running...');
        const raw = EcomCRUD.formData('whatif-form');
        const payload = { visitor_id: raw.visitor_id, scenario: {} };
        Object.keys(raw).forEach(k => {
            if (k.startsWith('scenario[')) {
                payload.scenario[k.replace('scenario[','').replace(']','')] = parseFloat(raw[k]) || 0;
            }
        });
        EcomAPI.post(API + '/what-if', payload).then(json => {
            const r = json.data || {};
            let html = '';
            Object.entries(r).forEach(([key, val]) => {
                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const display = typeof val === 'number' ? (key.includes('clv') || key.includes('value') || key.includes('revenue') ? '$'+EcomUtils.number(val) : EcomUtils.number(val)) : val;
                html += `<tr><td class="fw-medium">${label}</td><td class="text-end">${display}</td></tr>`;
            });
            if (!html) html = '<tr><td colspan="2" class="text-muted">No result data</td></tr>';
            $('#whatif-tbody').html(html);
            $('#whatif-result').slideDown();
        }).catch(err => toastr.error(err.message || 'What-if analysis failed'))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-play me-1"></i> Run Scenario'));
    });
});
</script>
@endsection
