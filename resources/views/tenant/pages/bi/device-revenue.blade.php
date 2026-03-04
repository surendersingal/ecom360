@extends('layouts.tenant')

@section('title', 'Device × Revenue Mapping')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Device × Revenue Mapping</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Device × Revenue Mapping</li>
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
                            <p class="text-muted fw-medium">Device Types</p>
                            <h4 class="mb-0">{{ number_format(count($data['devices'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-devices font-size-24"></i>
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
                            <p class="text-muted fw-medium">Top Revenue Device</p>
                            <h4 class="mb-0">{{ $data['revenue_by_device']['top'] ?? '—' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-dollar-circle font-size-24"></i>
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
                            <p class="text-muted fw-medium">Highest Conversion Device</p>
                            <h4 class="mb-0">{{ $data['conversion_by_device']['top'] ?? '—' }}</h4>
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

    {{-- Data Section --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Revenue by Device Type</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Device Type</th>
                                    <th>Sessions</th>
                                    <th>Revenue</th>
                                    <th>Conversion Rate</th>
                                    <th>AOV</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['devices'] ?? [] as $device)
                                    <tr>
                                        <td>
                                            <i class="bx bx-{{ ($device['type'] ?? '') === 'mobile' ? 'mobile-alt' : (($device['type'] ?? '') === 'tablet' ? 'tab' : 'desktop') }} me-1 font-size-18 text-primary"></i>
                                            {{ ucfirst($device['type'] ?? '-') }}
                                        </td>
                                        <td>{{ number_format($device['sessions'] ?? 0) }}</td>
                                        <td>${{ number_format($device['revenue'] ?? 0, 2) }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($device['conversion_rate'] ?? 0) >= 3 ? 'success' : (($device['conversion_rate'] ?? 0) >= 1.5 ? 'warning' : 'danger') }}">
                                                {{ number_format($device['conversion_rate'] ?? 0, 2) }}%
                                            </span>
                                        </td>
                                        <td>${{ number_format($device['aov'] ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No device data available.</td>
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
