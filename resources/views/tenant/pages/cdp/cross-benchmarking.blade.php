@extends('layouts.tenant')

@section('title', 'Cross-Tenant Benchmarking')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Cross-Tenant Benchmarking</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Cross-Tenant Benchmarking</li>
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
                            <p class="text-muted fw-medium">Your Percentile</p>
                            <h4 class="mb-0">
                                {{ safe_num($data['percentile'] ?? 0) }}
                                <span class="font-size-14 text-muted">/ 100</span>
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-trophy font-size-24"></i>
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
                            <p class="text-muted fw-medium">Industry Average</p>
                            <h4 class="mb-0">{{ $data['industry_avg'] ?? 'N/A' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-bar-chart-alt-2 font-size-24"></i>
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
                            <p class="text-muted fw-medium">Benchmarks Analyzed</p>
                            <h4 class="mb-0">{{ number_format(count($data['benchmarks'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-analyse font-size-24"></i>
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
                    <h4 class="card-title mb-4">Benchmark Comparison</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Metric</th>
                                    <th>Your Value</th>
                                    <th>Industry Avg</th>
                                    <th>Percentile</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['benchmarks'] ?? []) as $index => $benchmark)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $benchmark['name'] ?? '-' }}</td>
                                        <td><strong>{{ $benchmark['your_value'] ?? '-' }}</strong></td>
                                        <td>{{ $benchmark['industry_avg'] ?? '-' }}</td>
                                        <td>
                                            @php $pct = $benchmark['percentile'] ?? 0; @endphp
                                            <span class="badge bg-{{ $pct >= 75 ? 'success' : ($pct >= 50 ? 'info' : ($pct >= 25 ? 'warning' : 'danger')) }}">
                                                {{ $pct }}th
                                            </span>
                                        </td>
                                        <td>
                                            @php $trend = $benchmark['trend'] ?? 'flat'; @endphp
                                            <i class="bx bx-trending-{{ $trend === 'up' ? 'up text-success' : ($trend === 'down' ? 'down text-danger' : 'up text-muted') }} font-size-18"></i>
                                            {{ ucfirst($trend) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No benchmark data available.</td>
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
