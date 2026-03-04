@extends('layouts.tenant')

@section('title', 'Return Rate Anomaly Detection')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Return Rate Anomaly Detection</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Return Rate Anomaly Detection</li>
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
                            <p class="text-muted fw-medium">Anomalies Detected</p>
                            <h4 class="mb-0">{{ number_format(count($data['anomalies'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-error-circle font-size-24"></i>
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
                            <p class="text-muted fw-medium">Current Return Rate</p>
                            <h4 class="mb-0">{{ safe_num($data['return_rate_trend']['current'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-revision font-size-24"></i>
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
                            <p class="text-muted fw-medium">Top Returned Products</p>
                            <h4 class="mb-0">{{ number_format(count($data['top_returned_products'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-undo font-size-24"></i>
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
                    <h4 class="card-title mb-4">Return Anomalies by Product</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product Name</th>
                                    <th>Return Rate</th>
                                    <th>Anomaly Score</th>
                                    <th>Reason</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['anomalies'] ?? [] as $anomaly)
                                    <tr>
                                        <td>{{ $anomaly['name'] ?? '-' }}</td>
                                        <td>{{ number_format($anomaly['return_rate'] ?? 0, 1) }}%</td>
                                        <td>
                                            <span class="badge bg-{{ ($anomaly['anomaly_score'] ?? 0) >= 0.8 ? 'danger' : (($anomaly['anomaly_score'] ?? 0) >= 0.5 ? 'warning' : 'info') }}">
                                                {{ number_format($anomaly['anomaly_score'] ?? 0, 2) }}
                                            </span>
                                        </td>
                                        <td>{{ $anomaly['reason'] ?? '-' }}</td>
                                        <td>
                                            @if(($anomaly['trend'] ?? '') === 'up')
                                                <i class="bx bx-up-arrow-alt text-danger font-size-18"></i> Rising
                                            @elseif(($anomaly['trend'] ?? '') === 'down')
                                                <i class="bx bx-down-arrow-alt text-success font-size-18"></i> Falling
                                            @else
                                                <i class="bx bx-minus text-muted font-size-18"></i> Stable
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No return anomalies detected.</td>
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
