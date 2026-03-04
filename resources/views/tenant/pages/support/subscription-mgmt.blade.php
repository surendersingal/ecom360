@extends('layouts.tenant')

@section('title', 'Subscription Management Bot')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Subscription Management Bot</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Customer Support</li>
                        <li class="breadcrumb-item active">Subscription Management Bot</li>
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
                            <p class="text-muted fw-medium">Active Subscriptions</p>
                            <h4 class="mb-0">{{ safe_num($data['active_subscriptions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-repeat font-size-24"></i>
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
                            <p class="text-muted fw-medium">Changes Processed</p>
                            <h4 class="mb-0">{{ safe_num($data['changes'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-transfer font-size-24"></i>
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
                            <p class="text-muted fw-medium">Churn Prevented</p>
                            <h4 class="mb-0">{{ safe_num($data['churn_prevented'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-user-check font-size-24"></i>
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
                    <h4 class="card-title mb-4">Subscriptions</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Customer</th>
                                    <th>Plan</th>
                                    <th>Action</th>
                                    <th>Date</th>
                                    <th>Outcome</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['items'] ?? []) as $item)
                                    <tr>
                                        <td>{{ $item['customer'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-primary">{{ $item['plan'] ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ ($item['action'] ?? '') === 'upgrade' ? 'success' : (($item['action'] ?? '') === 'downgrade' ? 'warning' : (($item['action'] ?? '') === 'cancel' ? 'danger' : 'info')) }}">
                                                {{ ucfirst($item['action'] ?? 'unknown') }}
                                            </span>
                                        </td>
                                        <td>{{ $item['date'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge rounded-pill bg-{{ ($item['outcome'] ?? '') === 'retained' ? 'success' : (($item['outcome'] ?? '') === 'churned' ? 'danger' : 'secondary') }}">
                                                {{ ucfirst($item['outcome'] ?? 'unknown') }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No subscription activity found.</td>
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
