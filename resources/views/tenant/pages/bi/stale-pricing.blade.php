@extends('layouts.tenant')

@section('title', 'Stale Inventory Auto-Pricing')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Stale Inventory Auto-Pricing</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Stale Inventory Auto-Pricing</li>
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
                            <p class="text-muted fw-medium">Stale Products</p>
                            <h4 class="mb-0">{{ safe_num($data['stale_products'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-package font-size-24"></i>
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
                            <p class="text-muted fw-medium">Price Adjustments</p>
                            <h4 class="mb-0">{{ safe_num($data['price_adjustments'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-adjust font-size-24"></i>
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
                            <p class="text-muted fw-medium">Projected Savings</p>
                            <h4 class="mb-0">${{ safe_num($data['projected_savings'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-dollar-circle font-size-24"></i>
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
                    <h4 class="card-title mb-4">Stale Inventory Products</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product Name</th>
                                    <th>Days Stale</th>
                                    <th>Current Price</th>
                                    <th>Suggested Price</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['products'] ?? [] as $product)
                                    <tr>
                                        <td>{{ $product['name'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($product['days_stale'] ?? 0) > 60 ? 'danger' : (($product['days_stale'] ?? 0) > 30 ? 'warning' : 'info') }} bg-soft text-{{ ($product['days_stale'] ?? 0) > 60 ? 'danger' : (($product['days_stale'] ?? 0) > 30 ? 'warning' : 'info') }}">
                                                {{ $product['days_stale'] ?? 0 }} days
                                            </span>
                                        </td>
                                        <td>${{ number_format($product['current_price'] ?? 0, 2) }}</td>
                                        <td>${{ number_format($product['suggested_price'] ?? 0, 2) }}</td>
                                        <td>{{ $product['action'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No stale inventory products found.</td>
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
