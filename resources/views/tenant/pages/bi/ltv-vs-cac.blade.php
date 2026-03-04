@extends('layouts.tenant')

@section('title', 'LTV vs CAC Health Monitor')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">LTV vs CAC Health Monitor</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">LTV vs CAC Health Monitor</li>
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
                            <p class="text-muted fw-medium">Avg LTV</p>
                            <h4 class="mb-0">${{ safe_num($data['ltv'] ?? 0, 2) }}</h4>
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
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Avg CAC</p>
                            <h4 class="mb-0">${{ safe_num($data['cac'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-user-plus font-size-24"></i>
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
                            <p class="text-muted fw-medium">LTV:CAC Ratio</p>
                            <h4 class="mb-0">
                                <span class="text-{{ ($data['ratio'] ?? 0) >= 3 ? 'success' : (($data['ratio'] ?? 0) >= 1 ? 'warning' : 'danger') }}">
                                    {{ safe_num($data['ratio'] ?? 0, 2) }}x
                                </span>
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-bar-chart font-size-24"></i>
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
                    <h4 class="card-title mb-4">Channel Health Breakdown</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Channel</th>
                                    <th>LTV</th>
                                    <th>CAC</th>
                                    <th>Ratio</th>
                                    <th>Health</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['channels'] ?? [] as $channel)
                                    <tr>
                                        <td>{{ $channel['name'] ?? '-' }}</td>
                                        <td>${{ number_format($channel['ltv'] ?? 0, 2) }}</td>
                                        <td>${{ number_format($channel['cac'] ?? 0, 2) }}</td>
                                        <td>{{ number_format($channel['ratio'] ?? 0, 2) }}x</td>
                                        <td>
                                            @php $health = $channel['health'] ?? 'unknown'; @endphp
                                            <span class="badge bg-{{ $health === 'healthy' ? 'success' : ($health === 'warning' ? 'warning' : 'danger') }} bg-soft text-{{ $health === 'healthy' ? 'success' : ($health === 'warning' ? 'warning' : 'danger') }}">
                                                <i class="bx bx-{{ $health === 'healthy' ? 'check-circle' : ($health === 'warning' ? 'error' : 'x-circle') }} me-1"></i>
                                                {{ ucfirst($health) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No channel data available.</td>
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
