@extends('layouts.tenant')

@section('title', 'Multi-Touch Attribution')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Multi-Touch Attribution</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Multi-Touch Attribution</li>
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
                            <p class="text-muted fw-medium">Attribution Model</p>
                            <h4 class="mb-0">{{ ucfirst($data['attribution_model'] ?? 'Linear') }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-git-branch font-size-24"></i>
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
                            <p class="text-muted fw-medium">Channels Tracked</p>
                            <h4 class="mb-0">{{ number_format(count($data['channels'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-broadcast font-size-24"></i>
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
                            <p class="text-muted fw-medium">Avg ROAS</p>
                            <h4 class="mb-0">
                                @php
                                    $roasValues = array_column($data['roas_by_channel'] ?? [], 'roas');
                                    $avgRoas = count($roasValues) ? array_sum($roasValues) / count($roasValues) : 0;
                                @endphp
                                {{ number_format($avgRoas, 2) }}x
                            </h4>
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
    </div>

    {{-- Data Section --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Channel Attribution Breakdown</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Channel</th>
                                    <th>First-Touch %</th>
                                    <th>Last-Touch %</th>
                                    <th>Linear %</th>
                                    <th>ROAS</th>
                                    <th>Spend</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['roas_by_channel'] ?? []) as $index => $channel)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $channel['name'] ?? '-' }}</td>
                                        <td>{{ number_format($channel['first_touch_pct'] ?? 0, 1) }}%</td>
                                        <td>{{ number_format($channel['last_touch_pct'] ?? 0, 1) }}%</td>
                                        <td>{{ number_format($channel['linear_pct'] ?? 0, 1) }}%</td>
                                        <td>
                                            <span class="badge bg-{{ ($channel['roas'] ?? 0) >= 3 ? 'success' : (($channel['roas'] ?? 0) >= 1 ? 'warning' : 'danger') }}">
                                                {{ number_format($channel['roas'] ?? 0, 2) }}x
                                            </span>
                                        </td>
                                        <td>${{ number_format($channel['spend'] ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No attribution data available.</td>
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
