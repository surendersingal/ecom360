@extends('layouts.tenant')

@section('title', 'Offline-Online Identity Stitching')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Offline-Online Identity Stitching</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Offline-Online Identity Stitching</li>
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
                            <p class="text-muted fw-medium">Stitched Profiles</p>
                            <h4 class="mb-0">{{ safe_num($data['stitched_profiles'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
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
                            <p class="text-muted fw-medium">Match Rate %</p>
                            <h4 class="mb-0">{{ safe_num($data['match_rate'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-target-lock font-size-24"></i>
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
                            <p class="text-muted fw-medium">Channels Mapped</p>
                            <h4 class="mb-0">{{ safe_num($data['channels'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-link font-size-24"></i>
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
                    <h4 class="card-title mb-4">Stitched Identity Profiles</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Customer ID</th>
                                    <th>Online ID</th>
                                    <th>Offline ID</th>
                                    <th>Match Confidence</th>
                                    <th>Channels</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['profiles'] ?? []) as $index => $profile)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $profile['customer_id'] ?? '-' }}</td>
                                        <td><code>{{ $profile['online_id'] ?? '-' }}</code></td>
                                        <td><code>{{ $profile['offline_id'] ?? '-' }}</code></td>
                                        <td>
                                            @php $confidence = $profile['match_confidence'] ?? 0; @endphp
                                            <span class="badge bg-{{ $confidence >= 80 ? 'success' : ($confidence >= 50 ? 'warning' : 'danger') }}">
                                                {{ $confidence }}%
                                            </span>
                                        </td>
                                        <td>{{ $profile['channels'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No stitched profiles found.</td>
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
