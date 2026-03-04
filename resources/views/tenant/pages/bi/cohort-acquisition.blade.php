@extends('layouts.tenant')

@section('title', 'Cohort by Acquisition Source')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Cohort by Acquisition Source</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Cohort by Acquisition Source</li>
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
                            <p class="text-muted fw-medium">Total Cohorts</p>
                            <h4 class="mb-0">{{ number_format(count($data['cohorts'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-group font-size-24"></i>
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
                            <p class="text-muted fw-medium">Best Source</p>
                            <h4 class="mb-0">{{ $data['best_source'] ?? '—' }}</h4>
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
                            <p class="text-muted fw-medium">Avg Retention</p>
                            <h4 class="mb-0">
                                @if(isset($data['retention_matrix']['avg']))
                                    {{ safe_num($data['retention_matrix']['avg'], 1) }}%
                                @else
                                    —
                                @endif
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-line-chart font-size-24"></i>
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
                    <h4 class="card-title mb-4">Cohort Retention by Source</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Source</th>
                                    <th>Customers</th>
                                    <th>30d Retention</th>
                                    <th>60d Retention</th>
                                    <th>90d Retention</th>
                                    <th>LTV</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['cohorts'] ?? [] as $cohort)
                                    <tr>
                                        <td>{{ $cohort['source'] ?? '-' }}</td>
                                        <td>{{ number_format($cohort['customers'] ?? 0) }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($cohort['retention_30d'] ?? 0) >= 50 ? 'success' : (($cohort['retention_30d'] ?? 0) >= 25 ? 'warning' : 'danger') }} bg-soft text-{{ ($cohort['retention_30d'] ?? 0) >= 50 ? 'success' : (($cohort['retention_30d'] ?? 0) >= 25 ? 'warning' : 'danger') }}">
                                                {{ number_format($cohort['retention_30d'] ?? 0, 1) }}%
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ ($cohort['retention_60d'] ?? 0) >= 40 ? 'success' : (($cohort['retention_60d'] ?? 0) >= 20 ? 'warning' : 'danger') }} bg-soft text-{{ ($cohort['retention_60d'] ?? 0) >= 40 ? 'success' : (($cohort['retention_60d'] ?? 0) >= 20 ? 'warning' : 'danger') }}">
                                                {{ number_format($cohort['retention_60d'] ?? 0, 1) }}%
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ ($cohort['retention_90d'] ?? 0) >= 30 ? 'success' : (($cohort['retention_90d'] ?? 0) >= 15 ? 'warning' : 'danger') }} bg-soft text-{{ ($cohort['retention_90d'] ?? 0) >= 30 ? 'success' : (($cohort['retention_90d'] ?? 0) >= 15 ? 'warning' : 'danger') }}">
                                                {{ number_format($cohort['retention_90d'] ?? 0, 1) }}%
                                            </span>
                                        </td>
                                        <td>${{ number_format($cohort['ltv'] ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No cohort data available.</td>
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
