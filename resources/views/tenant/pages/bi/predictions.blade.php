@extends('layouts.tenant')

@section('title', 'Predictions')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Predictions</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Predictions</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        @php
            $models = [
                ['key' => 'clv', 'name' => 'Customer Lifetime Value', 'icon' => 'bx-trophy', 'color' => 'primary', 'desc' => 'Predict future revenue from each customer segment.'],
                ['key' => 'churn_risk', 'name' => 'Churn Probability', 'icon' => 'bx-user-minus', 'color' => 'danger', 'desc' => 'Identify customers at risk of churning.'],
                ['key' => 'revenue_forecast', 'name' => 'Revenue Forecast', 'icon' => 'bx-trending-up', 'color' => 'success', 'desc' => 'Forecast revenue for the next 30/60/90 days.'],
                ['key' => 'purchase_propensity', 'name' => 'Purchase Propensity', 'icon' => 'bx-cart', 'color' => 'warning', 'desc' => 'Score visitors on their likelihood to purchase.'],
            ];
        @endphp
        @foreach($models as $m)
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body text-center py-4">
                    <div class="avatar-md mx-auto mb-3">
                        <span class="avatar-title rounded-circle bg-soft-{{ $m['color'] }} text-{{ $m['color'] }} font-size-24">
                            <i class="bx {{ $m['icon'] }}"></i>
                        </span>
                    </div>
                    <h5 class="mb-1">{{ $m['name'] }}</h5>
                    <p class="text-muted font-size-13 mb-3">{{ $m['desc'] }}</p>
                    <button class="btn btn-soft-primary btn-sm btn-generate" data-model="{{ $m['key'] }}"><i class="bx bx-play me-1"></i> Generate</button>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Recent Predictions</h4>
                    <div class="table-responsive">
                        <table id="predictions-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Model</th>
                                    <th>Generated At</th>
                                    <th>Records</th>
                                    <th>Confidence</th>
                                    <th>Status</th>
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
    const API_BASE = '/bi/insights';

    const table = $('#predictions-table').DataTable({
        ajax: {
            url: EcomAPI.baseUrl + API_BASE + '/predictions',
            dataSrc: function(json) { return json.data?.data || json.data || []; },
            beforeSend: function(xhr) { xhr.setRequestHeader('Accept', 'application/json'); },
            error: function() { toastr.error('Failed to load predictions'); }
        },
        columns: [
            { data: 'model_type', render: (d) => `<span class="badge bg-soft-primary text-primary">${(d||'').replace(/_/g,' ')}</span>` },
            { data: 'created_at', render: (d) => EcomUtils.formatDate(d) },
            { data: 'record_count', render: (d) => EcomUtils.number(d) },
            { data: 'confidence', render: (d) => d ? EcomUtils.percent(d * 100) : '—' },
            { data: 'status', render: (d) => EcomUtils.statusBadge(d || 'completed') },
        ],
        order: [[1, 'desc']],
        responsive: true,
        language: { emptyTable: 'No predictions generated yet. Click "Generate" on a model above.' },
    });

    // Generate
    $('.btn-generate').on('click', function() {
        const $btn = $(this);
        const modelType = $btn.data('model');
        $btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Generating...');
        EcomAPI.post(API_BASE + '/predictions/generate', { model_type: modelType }).then(json => {
            toastr.success('Prediction generated successfully');
            table.ajax.reload();
        }).catch(err => toastr.error(err.message))
        .finally(() => $btn.prop('disabled', false).html('<i class="bx bx-play me-1"></i> Generate'));
    });
});
</script>
@endsection
