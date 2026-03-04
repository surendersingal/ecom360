@extends('layouts.tenant')

@section('title', 'Payday Surge Pricing')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Payday Surge Pricing</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Payday Surge Pricing</li>
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
                            <p class="text-muted fw-medium">Surge Windows</p>
                            <h4 class="mb-0">{{ count($data['surge_windows'] ?? []) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-time-five font-size-24"></i>
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
                            <p class="text-muted fw-medium">Active Campaigns</p>
                            <h4 class="mb-0">{{ count($data['campaigns'] ?? []) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-rocket font-size-24"></i>
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
                            <p class="text-muted fw-medium">Revenue Uplift</p>
                            <h4 class="mb-0">${{ safe_num($data['revenue_uplift'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-trending-up font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Data Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4"><i class="bx bx-money me-1"></i> Surge Windows</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Date Range</th>
                                    <th>Discount %</th>
                                    <th>Projected Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['surge_windows'] ?? [] as $index => $window)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $window['dates'] ?? '-' }}</td>
                                        <td>{{ $window['discount_percent'] ?? 0 }}%</td>
                                        <td>${{ number_format($window['projected_revenue'] ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No surge windows found.</td>
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
