@extends('layouts.tenant')

@section('title', 'VIP Greeting Protocol')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">VIP Greeting Protocol</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Customer Support</li>
                        <li class="breadcrumb-item active">VIP Greeting Protocol</li>
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
                            <p class="text-muted fw-medium">VIP Sessions</p>
                            <h4 class="mb-0">{{ safe_num($data['vip_sessions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-crown font-size-24"></i>
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
                            <p class="text-muted fw-medium">Greetings Sent</p>
                            <h4 class="mb-0">{{ safe_num($data['greetings_sent'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-message-dots font-size-24"></i>
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
                            <p class="text-muted fw-medium">Satisfaction Score</p>
                            <h4 class="mb-0">{{ safe_num($data['satisfaction_score'] ?? 0, 1) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-happy-heart-eyes font-size-24"></i>
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
                    <h4 class="card-title mb-4">VIP Sessions</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Customer</th>
                                    <th>Tier</th>
                                    <th>Greeting Type</th>
                                    <th>Response</th>
                                    <th>Satisfaction</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['items'] ?? []) as $item)
                                    <tr>
                                        <td>{{ $item['customer'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($item['tier'] ?? '') === 'platinum' ? 'dark' : (($item['tier'] ?? '') === 'gold' ? 'warning' : 'secondary') }}">
                                                {{ ucfirst($item['tier'] ?? 'standard') }}
                                            </span>
                                        </td>
                                        <td>{{ $item['greeting_type'] ?? '-' }}</td>
                                        <td>{{ $item['response'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($item['satisfaction'] ?? 0) >= 4 ? 'success' : (($item['satisfaction'] ?? 0) >= 3 ? 'warning' : 'danger') }}">
                                                {{ $item['satisfaction'] ?? '-' }} / 5
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No VIP sessions found.</td>
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
