@extends('layouts.tenant')

@section('title', 'Shipping Cost Analyzer')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Shipping Cost Analyzer</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Shipping Cost Analyzer</li>
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
                            <p class="text-muted fw-medium">Active Carriers</p>
                            <h4 class="mb-0">{{ number_format(count($data['carriers'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-truck font-size-24"></i>
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
                            <p class="text-muted fw-medium">Total Shipping Cost</p>
                            <h4 class="mb-0">${{ safe_num($data['cost_breakdown']['total'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-money font-size-24"></i>
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
                            <p class="text-muted fw-medium">Savings Opportunities</p>
                            <h4 class="mb-0">{{ number_format(count($data['optimization_tips'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-target-lock font-size-24"></i>
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
                    <h4 class="card-title mb-4">Carrier Performance</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Carrier Name</th>
                                    <th>Shipments</th>
                                    <th>Avg Cost</th>
                                    <th>On-Time %</th>
                                    <th>Recommendation</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['carriers'] ?? [] as $carrier)
                                    <tr>
                                        <td>{{ $carrier['name'] ?? '-' }}</td>
                                        <td>{{ number_format($carrier['shipments'] ?? 0) }}</td>
                                        <td>${{ number_format($carrier['avg_cost'] ?? 0, 2) }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($carrier['on_time_pct'] ?? 0) >= 90 ? 'success' : (($carrier['on_time_pct'] ?? 0) >= 75 ? 'warning' : 'danger') }}">
                                                {{ number_format($carrier['on_time_pct'] ?? 0, 1) }}%
                                            </span>
                                        </td>
                                        <td>{{ $carrier['recommendation'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No carrier data available.</td>
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
