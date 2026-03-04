@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <!-- Breadcrumb -->
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Dashboard</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item active">Dashboard</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Stores</p>
                            <h4 class="mb-0">{{ $stats['total_tenants'] }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-primary">
                                <span class="avatar-title">
                                    <i class="bx bx-buildings font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Active Stores</p>
                            <h4 class="mb-0">{{ $stats['active_tenants'] }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-success">
                                <span class="avatar-title bg-success">
                                    <i class="bx bx-check-circle font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Verified Stores</p>
                            <h4 class="mb-0">{{ $stats['verified_tenants'] }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-info">
                                <span class="avatar-title bg-info">
                                    <i class="bx bx-badge-check font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Total Users</p>
                            <h4 class="mb-0">{{ $stats['total_users'] }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-warning">
                                <span class="avatar-title bg-warning">
                                    <i class="bx bx-user font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Quick Actions</h4>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.tenants.create') }}" class="btn btn-primary waves-effect waves-light">
                            <i class="bx bx-plus me-1"></i> New Store
                        </a>
                        <a href="{{ route('admin.users.create') }}" class="btn btn-info waves-effect waves-light">
                            <i class="bx bx-user-plus me-1"></i> New User
                        </a>
                        <a href="{{ route('admin.system-health') }}" class="btn btn-success waves-effect waves-light">
                            <i class="bx bx-heart me-1"></i> System Health
                        </a>
                        <a href="{{ route('admin.analytics.platform') }}" class="btn btn-warning waves-effect waves-light">
                            <i class="bx bx-bar-chart me-1"></i> Platform Analytics
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Stores -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Recent Stores</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTenants as $tenant)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-body fw-bold">
                                            {{ $tenant->name }}
                                        </a>
                                    </td>
                                    <td><code>{{ $tenant->slug }}</code></td>
                                    <td>
                                        @if($tenant->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-danger">Inactive</span>
                                        @endif
                                        @if($tenant->is_verified)
                                            <span class="badge bg-info">Verified</span>
                                        @endif
                                    </td>
                                    <td>{{ $tenant->created_at->diffForHumans() }}</td>
                                    <td>
                                        <a href="{{ route('admin.impersonate.start', $tenant) }}" class="btn btn-sm btn-soft-primary" title="Login as tenant">
                                            <i class="bx bx-log-in-circle"></i>
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No stores yet</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Users -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Recent Users</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Store</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentUsers as $user)
                                <tr>
                                    <td>{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>{{ $user->tenant?->name ?? '-' }}</td>
                                    <td>{{ $user->created_at->diffForHumans() }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No users yet</td>
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
