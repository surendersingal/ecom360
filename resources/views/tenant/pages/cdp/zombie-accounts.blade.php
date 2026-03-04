@extends('layouts.tenant')

@section('title', 'Zombie Account Reactivation')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Zombie Account Reactivation</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Zombie Account Reactivation</li>
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
                            <p class="text-muted fw-medium">Zombie Accounts</p>
                            <h4 class="mb-0">{{ safe_num($data['zombie_accounts'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-ghost font-size-24"></i>
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
                            <p class="text-muted fw-medium">Campaigns Sent</p>
                            <h4 class="mb-0">{{ safe_num($data['reactivation_campaigns'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-mail-send font-size-24"></i>
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
                            <p class="text-muted fw-medium">Recovery Rate %</p>
                            <h4 class="mb-0">{{ safe_num($data['recovery_rate'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-revision font-size-24"></i>
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
                    <h4 class="card-title mb-4">Zombie Accounts</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Email</th>
                                    <th>Last Activity</th>
                                    <th>Days Dormant</th>
                                    <th>Campaign</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['accounts'] ?? []) as $index => $account)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $account['email'] ?? '-' }}</td>
                                        <td>{{ $account['last_activity'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($account['days_dormant'] ?? 0) > 180 ? 'danger' : 'warning' }}">
                                                {{ $account['days_dormant'] ?? 0 }} days
                                            </span>
                                        </td>
                                        <td>{{ $account['campaign'] ?? '-' }}</td>
                                        <td>
                                            @php $status = $account['status'] ?? 'dormant'; @endphp
                                            <span class="badge bg-{{ $status === 'reactivated' ? 'success' : ($status === 'pending' ? 'warning' : 'secondary') }}">
                                                {{ ucfirst($status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No zombie accounts found.</td>
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
