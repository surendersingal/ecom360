@extends('layouts.tenant')
@section('title', 'Chatbot Analytics')

@section('content')
<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Chatbot Analytics</h4>
                    <div class="page-title-right">
                        <select id="dateRange" class="form-select form-select-sm" style="width:auto;" onchange="location.href='?days='+this.value">
                            @foreach([7=>'Last 7 days',14=>'Last 14 days',30=>'Last 30 days',60=>'Last 60 days',90=>'Last 90 days'] as $d => $l)
                            <option value="{{ $d }}" {{ ($days ?? 30) == $d ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- KPI Cards --}}
        <div class="row">
            <div class="col-xl-3 col-md-6">
                <div class="card mini-stats-wid">
                    <div class="card-body">
                        <div class="d-flex">
                            <div class="flex-grow-1">
                                <p class="text-muted fw-medium mb-1">Total Conversations</p>
                                <h4 class="mb-0">{{ number_format($analytics['total_conversations'] ?? 0) }}</h4>
                            </div>
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center">
                                <span class="avatar-title"><i class="bx bx-chat font-size-24"></i></span>
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
                                <p class="text-muted fw-medium mb-1">Resolution Rate</p>
                                <h4 class="mb-0 {{ ($analytics['resolution_rate'] ?? 0) > 70 ? 'text-success' : 'text-warning' }}">{{ $analytics['resolution_rate'] ?? 0 }}%</h4>
                            </div>
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center">
                                <span class="avatar-title"><i class="bx bx-check-circle font-size-24"></i></span>
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
                                <p class="text-muted fw-medium mb-1">Escalation Rate</p>
                                <h4 class="mb-0 {{ ($analytics['escalation_rate'] ?? 0) < 10 ? 'text-success' : 'text-danger' }}">{{ $analytics['escalation_rate'] ?? 0 }}%</h4>
                            </div>
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-danger align-self-center">
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
                                <p class="text-muted fw-medium mb-1">Avg Satisfaction</p>
                                <h4 class="mb-0">
                                    @if($analytics['avg_satisfaction'] ?? null)
                                        {{ number_format($analytics['avg_satisfaction'], 1) }} / 5
                                        @for($s = 1; $s <= 5; $s++)
                                            <i class="bx bx-star font-size-14 {{ $s <= round($analytics['avg_satisfaction']) ? 'text-warning' : 'text-muted' }}"></i>
                                        @endfor
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </h4>
                            </div>
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center">
                                <span class="avatar-title"><i class="bx bx-star font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Intent Breakdown --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-brain text-primary me-2"></i>Intent Breakdown</h4>
                        @php
                            $intentColors = [
                                'product_inquiry' => 'primary',
                                'order_tracking' => 'info',
                                'greeting' => 'success',
                                'checkout_help' => 'warning',
                                'return_request' => 'danger',
                                'coupon_inquiry' => 'secondary',
                                'shipping_inquiry' => 'dark',
                                'size_help' => 'primary',
                                'add_to_cart' => 'success',
                                'farewell' => 'secondary',
                                'general' => 'light',
                            ];
                            $totalIntents = array_sum($analytics['intent_breakdown'] ?? []);
                        @endphp

                        @forelse(($analytics['intent_breakdown'] ?? []) as $intent => $count)
                        @php $pct = $totalIntents > 0 ? round(($count / $totalIntents) * 100, 1) : 0; @endphp
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span>{{ ucwords(str_replace('_', ' ', $intent)) }}</span>
                                <span class="text-muted">{{ $count }} ({{ $pct }}%)</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-{{ $intentColors[$intent] ?? 'primary' }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                        @empty
                        <p class="text-muted text-center py-3">No intent data yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Quick Stats --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-bar-chart text-success me-2"></i>Performance Summary</h4>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <td>Period</td>
                                        <td class="text-end fw-medium">Last {{ $analytics['period_days'] ?? 30 }} days</td>
                                    </tr>
                                    <tr>
                                        <td>Total Conversations</td>
                                        <td class="text-end fw-medium">{{ $analytics['total_conversations'] ?? 0 }}</td>
                                    </tr>
                                    <tr>
                                        <td>Resolved by Bot</td>
                                        <td class="text-end fw-medium text-success">{{ $analytics['resolved'] ?? 0 }}</td>
                                    </tr>
                                    <tr>
                                        <td>Escalated to Human</td>
                                        <td class="text-end fw-medium text-danger">{{ $analytics['escalated'] ?? 0 }}</td>
                                    </tr>
                                    <tr>
                                        <td>Resolution Rate</td>
                                        <td class="text-end fw-medium">{{ $analytics['resolution_rate'] ?? 0 }}%</td>
                                    </tr>
                                    <tr>
                                        <td>Escalation Rate</td>
                                        <td class="text-end fw-medium">{{ $analytics['escalation_rate'] ?? 0 }}%</td>
                                    </tr>
                                    <tr>
                                        <td>Avg Satisfaction</td>
                                        <td class="text-end fw-medium">{{ $analytics['avg_satisfaction'] ? number_format($analytics['avg_satisfaction'], 1) . ' / 5' : 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td>Most Common Intent</td>
                                        <td class="text-end fw-medium">
                                            @php
                                                $topIntent = collect($analytics['intent_breakdown'] ?? [])->sort()->keys()->last();
                                            @endphp
                                            {{ $topIntent ? ucwords(str_replace('_', ' ', $topIntent)) : 'N/A' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
