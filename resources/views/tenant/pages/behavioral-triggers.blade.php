@extends('layouts.tenant')

@section('title', 'Behavioral Triggers')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Behavioral Triggers</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Monitoring</li>
                        <li class="breadcrumb-item active">Behavioral Triggers</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Trigger Type Cards --}}
    <div class="row" id="trigger-type-cards">
        @php
            $triggerTypes = [
                ['type' => 'cart_abandonment', 'label' => 'Cart Abandonment', 'icon' => 'bx bx-cart', 'color' => 'danger', 'desc' => 'Detect users who added items to cart but didn\'t purchase'],
                ['type' => 'browse_pattern', 'label' => 'Browse Patterns', 'icon' => 'bx bx-search-alt', 'color' => 'info', 'desc' => 'Repeated views of same product or category'],
                ['type' => 'high_intent', 'label' => 'High Intent Visitors', 'icon' => 'bx bx-target-lock', 'color' => 'warning', 'desc' => 'Multiple sessions, comparison behavior, price checks'],
                ['type' => 'purchase_milestone', 'label' => 'Purchase Milestones', 'icon' => 'bx bx-award', 'color' => 'success', 'desc' => 'First purchase, repeat purchase, VIP threshold'],
            ];
        @endphp

        @foreach($triggerTypes as $t)
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body text-center">
                    <div class="avatar-md mx-auto mb-3">
                        <span class="avatar-title rounded-circle bg-soft-{{ $t['color'] }} text-{{ $t['color'] }} font-size-24">
                            <i class="{{ $t['icon'] }}"></i>
                        </span>
                    </div>
                    <h5 class="mb-1">{{ $t['label'] }}</h5>
                    <p class="text-muted font-size-13 mb-2">{{ $t['desc'] }}</p>
                    <span class="badge bg-soft-secondary text-secondary trigger-count" data-type="{{ $t['type'] }}">—</span>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Evaluate & Results --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0"><i class="bx bx-target-lock text-warning me-1"></i> Triggered Events</h4>
                        <button class="btn btn-warning" id="btn-evaluate"><i class="bx bx-analyse me-1"></i> Evaluate Triggers</button>
                    </div>

                    <div id="triggers-loading" class="text-center py-4" style="display:none;">
                        <div class="spinner-border text-warning mb-3" role="status"></div>
                        <p class="text-muted">Evaluating behavioral triggers across all visitors...</p>
                    </div>

                    <div class="table-responsive" id="triggers-table-wrap">
                        <table id="triggers-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Visitor</th>
                                    <th>Trigger Type</th>
                                    <th>Action</th>
                                    <th>Confidence</th>
                                    <th>Details</th>
                                    <th>Detected At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="text-center text-muted py-4">Click "Evaluate Triggers" to scan for behavioral patterns.</td></tr>
                            </tbody>
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
    const API = '/analytics/advanced/triggers/evaluate';

    function typeColor(type) {
        const map = {cart_abandonment:'danger', browse_pattern:'info', high_intent:'warning', purchase_milestone:'success'};
        return map[type] || 'secondary';
    }

    function evaluateTriggers() {
        $('#triggers-loading').show();
        $('#triggers-table-wrap').hide();

        EcomAPI.post(API, {}).then(json => {
            const data = json.data || {};
            const triggers = data.triggers || data.results || data.events || (Array.isArray(data) ? data : []);

            // Update counters on cards
            const counts = {};
            triggers.forEach(t => { const type = t.trigger_type || t.type; counts[type] = (counts[type]||0)+1; });
            document.querySelectorAll('.trigger-count').forEach(el => {
                const type = el.dataset.type;
                const c = counts[type] || 0;
                el.textContent = c + ' detected';
                el.className = `badge ${c > 0 ? 'bg-'+typeColor(type) : 'bg-soft-secondary text-secondary'} trigger-count`;
                el.dataset.type = type;
            });

            // Populate table
            const $tbody = $('#triggers-table tbody').empty();
            if (triggers.length) {
                triggers.forEach(t => {
                    const type = t.trigger_type || t.type || '—';
                    const color = typeColor(type);
                    const confidence = t.confidence || t.score || null;
                    const details = t.details || t.description || t.message || '';
                    const detectedAt = t.detected_at || t.created_at || t.timestamp || '';
                    $tbody.append(`<tr>
                        <td><code>${t.visitor_id || t.customer_id || '—'}</code></td>
                        <td><span class="badge bg-soft-${color} text-${color}">${type.replace(/_/g,' ')}</span></td>
                        <td>${t.recommended_action || t.action || '—'}</td>
                        <td>${confidence !== null ? `<div class="progress" style="height:6px;width:80px;"><div class="progress-bar bg-${color}" style="width:${(confidence*100).toFixed(0)}%"></div></div><small>${(confidence*100).toFixed(0)}%</small>` : '—'}</td>
                        <td>${EcomUtils.truncate(details, 60)}</td>
                        <td>${detectedAt ? EcomUtils.formatDate(detectedAt) : '—'}</td>
                    </tr>`);
                });
            } else {
                $tbody.append('<tr><td colspan="6" class="text-center text-muted py-4">No behavioral triggers detected. This is normal if there\'s limited visitor activity.</td></tr>');
            }

            $('#triggers-loading').hide();
            $('#triggers-table-wrap').show();
            toastr.success(`Evaluation complete. ${triggers.length} trigger(s) detected.`);
        }).catch(err => {
            $('#triggers-loading').hide();
            $('#triggers-table-wrap').show();
            toastr.error(err.message || 'Evaluation failed');
        });
    }

    $('#btn-evaluate').on('click', evaluateTriggers);
});
</script>
@endsection
