@extends('layouts.tenant')

@section('title', 'Why Analysis')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Why Analysis</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI &amp; Insights</li>
                        <li class="breadcrumb-item active">Why Analysis</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Query Form --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-4">
                        <div class="avatar-sm me-3">
                            <span class="avatar-title rounded-circle bg-soft-primary text-primary font-size-20">
                                <i class="bx bx-help-circle"></i>
                            </span>
                        </div>
                        <div>
                            <h5 class="mb-0">Why did this metric change?</h5>
                            <p class="text-muted mb-0">Get AI-powered explanations for metric changes</p>
                        </div>
                    </div>
                    <form id="why-form" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Metric <span class="text-danger">*</span></label>
                            <select class="form-select" name="metric" required>
                                <option value="revenue">Revenue</option>
                                <option value="orders">Orders</option>
                                <option value="sessions">Sessions</option>
                                <option value="aov">Average Order Value</option>
                                <option value="conversion_rate">Conversion Rate</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" id="why-start" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" id="why-end" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Prev Start <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="prev_start_date">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Prev End <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="prev_end_date">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100"><i class="bx bx-analyse"></i></button>
                        </div>
                    </form>
                    <div class="mt-2">
                        <button class="btn btn-sm btn-outline-secondary me-1" data-preset="7">Last 7 days</button>
                        <button class="btn btn-sm btn-outline-secondary me-1" data-preset="30">Last 30 days</button>
                        <button class="btn btn-sm btn-outline-secondary" data-preset="90">Last 90 days</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Loading --}}
    <div class="row" id="why-loading" style="display:none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="text-muted">Analyzing metric changes... This may take a moment.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Results --}}
    <div class="row" id="why-results" style="display:none;">
        {{-- Summary --}}
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-body">
                    <h5 class="card-title"><i class="bx bx-message-square-detail text-primary me-1"></i> Explanation</h5>
                    <div id="why-narrative" class="p-3 bg-light rounded mb-3"></div>
                    <div class="row" id="why-summary-cards"></div>
                </div>
            </div>
        </div>

        {{-- Factors --}}
        <div class="col-12" id="why-factors-section" style="display:none;">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4"><i class="bx bx-list-check text-info me-1"></i> Contributing Factors</h5>
                    <div class="table-responsive">
                        <table class="table table-centered mb-0" id="factors-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Factor</th>
                                    <th class="text-end">Impact</th>
                                    <th class="text-end">Contribution</th>
                                    <th>Direction</th>
                                    <th>Details</th>
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
    const API = '/analytics/advanced/why';

    // Set default dates
    const today = new Date(), d7 = new Date(today); d7.setDate(d7.getDate()-7);
    $('#why-end').val(today.toISOString().split('T')[0]);
    $('#why-start').val(d7.toISOString().split('T')[0]);

    // Preset buttons
    $('[data-preset]').on('click', function() {
        const days = parseInt($(this).data('preset'));
        const t = new Date(), s = new Date(t); s.setDate(s.getDate()-days);
        $('#why-start').val(s.toISOString().split('T')[0]);
        $('#why-end').val(t.toISOString().split('T')[0]);
    });

    // Submit
    $('#why-form').on('submit', function(e) {
        e.preventDefault();
        const data = EcomCRUD.formData('why-form');
        if (!data.start_date || !data.end_date) { toastr.warning('Start and end dates are required'); return; }

        $('#why-results').hide();
        $('#why-loading').show();

        const payload = { metric: data.metric, start_date: data.start_date, end_date: data.end_date };
        if (data.prev_start_date) payload.prev_start_date = data.prev_start_date;
        if (data.prev_end_date) payload.prev_end_date = data.prev_end_date;

        EcomAPI.post(API, payload).then(json => {
            const result = json.data || {};

            // Narrative
            const narrative = result.narrative || result.explanation || result.summary || result.text || '';
            if (narrative) {
                $('#why-narrative').html(narrative);
            } else {
                $('#why-narrative').html('<span class="text-muted">No narrative explanation available.</span>');
            }

            // Summary cards
            const $cards = $('#why-summary-cards').empty();
            const summaryFields = ['metric','direction','change_amount','change_percent','current_value','previous_value'];
            summaryFields.forEach(f => {
                if (result[f] !== undefined) {
                    const label = f.replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase());
                    let val = result[f];
                    if (typeof val === 'number') val = f.includes('percent') ? val.toFixed(1)+'%' : EcomUtils.number(val);
                    $cards.append(`<div class="col-md-2"><div class="border rounded p-2 text-center"><small class="text-muted d-block">${label}</small><strong>${val}</strong></div></div>`);
                }
            });

            // Factors
            const factors = result.factors || result.contributors || result.causes || [];
            if (factors.length) {
                const $tbody = $('#factors-table tbody').empty();
                factors.forEach(f => {
                    const name = f.factor || f.name || f.cause || '—';
                    const impact = f.impact || f.value || null;
                    const contrib = f.contribution || f.percentage || null;
                    const dir = f.direction || (impact && impact > 0 ? 'positive' : 'negative') || '—';
                    const dirBadge = dir === 'positive' || dir === 'up'
                        ? '<span class="badge bg-success"><i class="bx bx-up-arrow-alt"></i> Positive</span>'
                        : '<span class="badge bg-danger"><i class="bx bx-down-arrow-alt"></i> Negative</span>';
                    $tbody.append(`<tr>
                        <td><strong>${name}</strong></td>
                        <td class="text-end">${impact !== null ? (impact > 0 ? '+' : '') + EcomUtils.number(impact) : '—'}</td>
                        <td class="text-end">${contrib !== null ? contrib.toFixed(1) + '%' : '—'}</td>
                        <td>${dirBadge}</td>
                        <td>${f.details || f.description || '—'}</td>
                    </tr>`);
                });
                $('#why-factors-section').show();
            } else {
                $('#why-factors-section').hide();
            }

            $('#why-loading').hide();
            $('#why-results').show();
        }).catch(err => {
            $('#why-loading').hide();
            toastr.error(err.message || 'Analysis failed');
        });
    });
});
</script>
@endsection
