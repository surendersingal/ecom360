@extends('layouts.tenant')

@section('title', 'GDPR Purge Simulator')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">GDPR Purge Simulator</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">GDPR Purge Simulator</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Warning Alert --}}
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center" role="alert">
                <i class="bx bx-info-circle font-size-22 me-2"></i>
                <div>
                    <strong>Simulation only</strong> — no data will be deleted. This tool estimates the impact of a GDPR purge on your datasets.
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
                            <p class="text-muted fw-medium">Affected Records</p>
                            <h4 class="mb-0">{{ safe_num($data['affected_records'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-user-x font-size-24"></i>
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
                            <p class="text-muted fw-medium">Tables Impacted</p>
                            <h4 class="mb-0">{{ number_format(count($data['tables'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-table font-size-24"></i>
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
                            <p class="text-muted fw-medium">Estimated Impact</p>
                            <h4 class="mb-0">{{ $data['estimated_impact'] ?? 'N/A' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-shield-quarter font-size-24"></i>
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
                    <h4 class="card-title mb-4">Purge Impact by Table</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Table Name</th>
                                    <th>Record Count</th>
                                    <th>Data Types</th>
                                    <th>Purge Impact</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['tables'] ?? []) as $index => $table)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td><code>{{ $table['name'] ?? '-' }}</code></td>
                                        <td>{{ number_format($table['record_count'] ?? 0) }}</td>
                                        <td>{{ $table['data_types'] ?? '-' }}</td>
                                        <td>
                                            @php $impact = $table['purge_impact'] ?? 'low'; @endphp
                                            <span class="badge bg-{{ $impact === 'high' ? 'danger' : ($impact === 'medium' ? 'warning' : 'success') }}">
                                                {{ ucfirst($impact) }}
                                            </span>
                                        </td>
                                        <td>
                                            @php $status = $table['status'] ?? 'pending'; @endphp
                                            <span class="badge bg-{{ $status === 'simulated' ? 'info' : ($status === 'ready' ? 'success' : 'secondary') }}">
                                                {{ ucfirst($status) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No table data available for simulation.</td>
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
