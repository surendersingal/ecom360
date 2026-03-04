@extends('layouts.admin')

@section('title', 'Cross-Tenant Benchmarking')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Cross-Tenant Benchmarking</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Analytics</li>
                        <li class="breadcrumb-item active">Cross-Tenant Benchmarking</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Active Tenants</p>
                            <h4 class="mb-0">{{ $tenants->count() }}</h4>
                        </div>
                        <div class="align-self-center flex-shrink-0">
                            <div class="avatar-sm rounded-circle bg-primary bg-soft-primary">
                                <span class="avatar-title rounded-circle bg-soft-primary text-primary font-size-24">
                                    <i class="bx bx-buildings"></i>
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
                            <p class="text-muted fw-medium">Benchmarks Computed</p>
                            <h4 class="mb-0">{{ collect($benchmarks)->count() }}</h4>
                        </div>
                        <div class="align-self-center flex-shrink-0">
                            <div class="avatar-sm rounded-circle bg-success bg-soft-success">
                                <span class="avatar-title rounded-circle bg-soft-success text-success font-size-24">
                                    <i class="bx bx-bar-chart-alt-2"></i>
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
                            <p class="text-muted fw-medium">Avg Percentile</p>
                            <h4 class="mb-0">{{ collect($benchmarks)->avg('percentile') ? round(collect($benchmarks)->avg('percentile'), 1) : 0 }}%</h4>
                        </div>
                        <div class="align-self-center flex-shrink-0">
                            <div class="avatar-sm rounded-circle bg-info bg-soft-info">
                                <span class="avatar-title rounded-circle bg-soft-info text-info font-size-24">
                                    <i class="bx bx-trophy"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Benchmarking Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4"><i class="bx bx-scatter-chart text-primary me-1"></i> Tenant Benchmark Comparison</h4>

                    <div class="table-responsive">
                        <table class="table table-hover table-centered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tenant</th>
                                    <th>Percentile</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($benchmarks as $id => $bm)
                                    <tr>
                                        <td>
                                            <h5 class="font-size-14 mb-0">{{ $bm['name'] ?? 'Unknown' }}</h5>
                                            <small class="text-muted">{{ $bm['slug'] ?? '' }}</small>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-grow-1 me-2">
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar bg-{{ ($bm['percentile'] ?? 0) >= 75 ? 'success' : (($bm['percentile'] ?? 0) >= 50 ? 'warning' : 'danger') }}"
                                                             role="progressbar" style="width: {{ $bm['percentile'] ?? 0 }}%"></div>
                                                    </div>
                                                </div>
                                                <span class="font-size-13 fw-medium">{{ $bm['percentile'] ?? 0 }}%</span>
                                            </div>
                                        </td>
                                        <td>
                                            @if(($bm['percentile'] ?? 0) >= 75)
                                                <span class="badge bg-soft-success text-success">Above Average</span>
                                            @elseif(($bm['percentile'] ?? 0) >= 50)
                                                <span class="badge bg-soft-warning text-warning">Average</span>
                                            @else
                                                <span class="badge bg-soft-danger text-danger">Below Average</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.analytics.tenants') }}" class="btn btn-sm btn-soft-primary">
                                                <i class="bx bx-right-arrow-alt"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No benchmark data available yet.</td>
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
