@extends('layouts.tenant')

@section('title', 'Visual Order Tracking')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Visual Order Tracking</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Customer Support</li>
                        <li class="breadcrumb-item active">Visual Order Tracking</li>
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
                            <p class="text-muted fw-medium">Tracked Orders</p>
                            <h4 class="mb-0">{{ safe_num($data['tracked_orders'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-map-pin font-size-24"></i>
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
                            <p class="text-muted fw-medium">Avg Delivery Time</p>
                            <h4 class="mb-0">{{ $data['avg_delivery_time'] ?? '0d' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-time font-size-24"></i>
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
                            <p class="text-muted fw-medium">Active Shipments</p>
                            <h4 class="mb-0">{{ safe_num($data['active_shipments'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-package font-size-24"></i>
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
                    <h4 class="card-title mb-4">Tracked Orders</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Carrier</th>
                                    <th>Status</th>
                                    <th>ETA</th>
                                    <th>Last Update</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['items'] ?? []) as $item)
                                    <tr>
                                        <td>{{ $item['order_id'] ?? '-' }}</td>
                                        <td>{{ $item['customer'] ?? '-' }}</td>
                                        <td>{{ $item['carrier'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge rounded-pill bg-{{ ($item['status'] ?? '') === 'delivered' ? 'success' : (($item['status'] ?? '') === 'in_transit' ? 'info' : (($item['status'] ?? '') === 'shipped' ? 'primary' : 'warning')) }}">
                                                {{ str_replace('_', ' ', ucfirst($item['status'] ?? 'unknown')) }}
                                            </span>
                                        </td>
                                        <td>{{ $item['eta'] ?? '-' }}</td>
                                        <td>{{ $item['last_update'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No tracked orders found.</td>
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
