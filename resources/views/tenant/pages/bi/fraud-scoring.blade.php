@extends('layouts.tenant')

@section('title', 'Real-Time Fraud Scoring')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Real-Time Fraud Scoring</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Real-Time Fraud Scoring</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Flagged Orders</p>
                            <h4 class="mb-0">{{ safe_num($data['flagged_orders'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-shield-x font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Risk Distribution</p>
                            <h4 class="mb-0">
                                @if(isset($data['risk_distribution']))
                                    <span class="text-danger">H:{{ $data['risk_distribution']['high'] ?? 0 }}</span>
                                    <span class="text-warning">M:{{ $data['risk_distribution']['medium'] ?? 0 }}</span>
                                    <span class="text-success">L:{{ $data['risk_distribution']['low'] ?? 0 }}</span>
                                @else
                                    —
                                @endif
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-bar-chart-alt-2 font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Blocked Amount</p>
                            <h4 class="mb-0">${{ safe_num($data['blocked_amount'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-block font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Data Section --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Flagged Orders</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Risk Score</th>
                                    <th>Flags</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['orders'] ?? [] as $order)
                                    <tr>
                                        <td>#{{ $order['id'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($order['risk_score'] ?? 0) >= 80 ? 'danger' : (($order['risk_score'] ?? 0) >= 50 ? 'warning' : 'success') }}">
                                                {{ $order['risk_score'] ?? 0 }}
                                            </span>
                                        </td>
                                        <td>{{ $order['flags'] ?? '-' }}</td>
                                        <td>${{ number_format($order['amount'] ?? 0, 2) }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($order['status'] ?? '') === 'blocked' ? 'danger' : (($order['status'] ?? '') === 'review' ? 'warning' : 'success') }} bg-soft text-{{ ($order['status'] ?? '') === 'blocked' ? 'danger' : (($order['status'] ?? '') === 'review' ? 'warning' : 'success') }}">
                                                {{ ucfirst($order['status'] ?? '-') }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No flagged orders found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
