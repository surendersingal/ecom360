@extends('layouts.tenant')

@section('title', 'Revenue Waterfall')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Revenue Waterfall</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Analytics</li>
                        <li class="breadcrumb-item active">Revenue Waterfall</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Date Range Filter --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form id="waterfall-filter" class="row align-items-end g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="wf-start">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="wf-end">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary"><i class="bx bx-search me-1"></i> Analyze</button>
                            <button type="button" class="btn btn-soft-secondary ms-1" id="btn-preset-30d">Last 30d</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row" id="wf-summary" style="display:none;">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium mb-2">Starting Revenue</p>
                            <h4 class="mb-0" id="stat-start-rev">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-primary">
                            <span class="avatar-title"><i class="bx bx-dollar-circle font-size-24"></i></span>
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
                            <p class="text-muted fw-medium mb-2">Ending Revenue</p>
                            <h4 class="mb-0" id="stat-end-rev">—</h4>
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
                            <p class="text-muted fw-medium mb-2">Net Change</p>
                            <h4 class="mb-0" id="stat-net-change">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-info">
                            <span class="avatar-title"><i class="bx bx-transfer-alt font-size-24"></i></span>
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
                            <p class="text-muted fw-medium mb-2">Change %</p>
                            <h4 class="mb-0" id="stat-change-pct">—</h4>
                        </div>
                        <div class="align-self-center mini-stat-icon avatar-sm rounded-circle bg-warning">
                            <span class="avatar-title"><i class="bx bx-percent font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Waterfall Breakdown Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Revenue Decomposition</h4>
                    <div id="waterfall-loading" class="text-center py-4" style="display:none;">
                        <i class="bx bx-loader-alt bx-spin font-size-24"></i>
                        <p class="text-muted mt-2">Analyzing revenue changes...</p>
                    </div>
                    <div class="table-responsive" id="waterfall-table-wrap" style="display:none;">
                        <table class="table table-centered mb-0" id="waterfall-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Component</th>
                                    <th class="text-end">Impact</th>
                                    <th class="text-end">% of Total Change</th>
                                    <th>Direction</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div id="waterfall-empty" class="text-center py-5">
                        <div class="avatar-lg mx-auto mb-3">
                            <div class="avatar-title bg-soft-primary text-primary rounded-circle font-size-24">
                                <i class="bx bx-dollar-circle"></i>
                            </div>
                        </div>
                        <p class="text-muted">Select a date range and click "Analyze" to decompose revenue changes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API = '/analytics/advanced/revenue-waterfall';

    // Set default dates (last 30 days)
    const today = new Date();
    const d30 = new Date(today); d30.setDate(d30.getDate() - 30);
    $('#wf-end').val(today.toISOString().split('T')[0]);
    $('#wf-start').val(d30.toISOString().split('T')[0]);

    $('#btn-preset-30d').on('click', function() {
        const t = new Date(), s = new Date(t); s.setDate(s.getDate()-30);
        $('#wf-start').val(s.toISOString().split('T')[0]);
        $('#wf-end').val(t.toISOString().split('T')[0]);
        $('#waterfall-filter').submit();
    });

    function loadWaterfall(startDate, endDate) {
        $('#waterfall-empty').hide();
        $('#waterfall-table-wrap').hide();
        $('#waterfall-loading').show();

        let url = API;
        const params = [];
        if (startDate) params.push('start_date=' + startDate);
        if (endDate) params.push('end_date=' + endDate);
        if (params.length) url += '?' + params.join('&');

        EcomAPI.get(url).then(json => {
            const data = json.data || {};
            const components = data.components || data.waterfall || data.breakdown || [];
            const summary = data.summary || data;

            // Update summary cards
            $('#wf-summary').show();
            $('#stat-start-rev').text('$' + EcomUtils.number(summary.starting_revenue || summary.previous_revenue || 0));
            $('#stat-end-rev').text('$' + EcomUtils.number(summary.ending_revenue || summary.current_revenue || 0));
            const net = (summary.ending_revenue || summary.current_revenue || 0) - (summary.starting_revenue || summary.previous_revenue || 0);
            $('#stat-net-change').html((net >= 0 ? '<span class="text-success">+$' : '<span class="text-danger">-$') + EcomUtils.number(Math.abs(net)) + '</span>');
            const pct = summary.change_percent || (summary.starting_revenue ? ((net / summary.starting_revenue) * 100) : 0);
            $('#stat-change-pct').html((pct >= 0 ? '<span class="text-success">+' : '<span class="text-danger">') + pct.toFixed(1) + '%</span>');

            // Populate table
            const $tbody = $('#waterfall-table tbody').empty();
            const totalAbsChange = Math.abs(net) || 1;

            if (Array.isArray(components) && components.length) {
                components.forEach(c => {
                    const impact = c.impact || c.value || c.amount || 0;
                    const pctOfTotal = ((Math.abs(impact) / totalAbsChange) * 100).toFixed(1);
                    const dir = impact >= 0 ? '<span class="text-success"><i class="bx bx-up-arrow-alt"></i> Increase</span>' : '<span class="text-danger"><i class="bx bx-down-arrow-alt"></i> Decrease</span>';
                    $tbody.append(`<tr>
                        <td><strong>${c.name || c.component || c.label || '—'}</strong><br><small class="text-muted">${c.description || ''}</small></td>
                        <td class="text-end ${impact >= 0 ? 'text-success' : 'text-danger'} fw-medium">${impact >= 0 ? '+' : ''}$${EcomUtils.number(Math.abs(impact))}</td>
                        <td class="text-end">${pctOfTotal}%</td>
                        <td>${dir}</td>
                    </tr>`);
                });
            } else if (typeof data === 'object') {
                // Fallback: treat data keys as components
                Object.entries(data).forEach(([key, val]) => {
                    if (typeof val === 'number' && !['starting_revenue','ending_revenue','change_percent','previous_revenue','current_revenue'].includes(key)) {
                        const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                        const dir = val >= 0 ? '<span class="text-success"><i class="bx bx-up-arrow-alt"></i></span>' : '<span class="text-danger"><i class="bx bx-down-arrow-alt"></i></span>';
                        $tbody.append(`<tr><td><strong>${label}</strong></td><td class="text-end ${val>=0?'text-success':'text-danger'}">${val>=0?'+':''}$${EcomUtils.number(Math.abs(val))}</td><td class="text-end">—</td><td>${dir}</td></tr>`);
                    }
                });
            }

            if (!$tbody.children().length) {
                $tbody.append('<tr><td colspan="4" class="text-center text-muted py-4">No waterfall data available for selected period.</td></tr>');
            }

            $('#waterfall-loading').hide();
            $('#waterfall-table-wrap').show();
        }).catch(err => {
            $('#waterfall-loading').hide();
            $('#waterfall-empty').show();
            toastr.error(err.message || 'Failed to load waterfall data');
        });
    }

    $('#waterfall-filter').on('submit', function(e) {
        e.preventDefault();
        loadWaterfall($('#wf-start').val(), $('#wf-end').val());
    });

    // Auto-load on page load
    loadWaterfall($('#wf-start').val(), $('#wf-end').val());
});
</script>
@endsection
