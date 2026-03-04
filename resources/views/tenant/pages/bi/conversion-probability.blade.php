@extends('layouts.tenant')

@section('title', 'Conversion Probability Scoring')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Conversion Probability Scoring</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Conversion Probability Scoring</li>
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
                            <p class="text-muted fw-medium">Sessions Analyzed</p>
                            <h4 class="mb-0">{{ number_format(count($data['sessions'] ?? [])) }}</h4>
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
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Avg Probability</p>
                            <h4 class="mb-0">{{ safe_num($data['avg_probability'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
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
                            <p class="text-muted fw-medium">High-Intent Sessions</p>
                            <h4 class="mb-0">
                                {{ number_format(collect($data['score_distribution'] ?? [])->where('bucket', 'high')->sum('count')) }}
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-rocket font-size-24"></i>
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
                    <h4 class="card-title mb-4">Session Conversion Scores</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Session ID</th>
                                    <th>Probability Score</th>
                                    <th>Page Views</th>
                                    <th>Time on Site</th>
                                    <th>Outcome</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['sessions'] ?? [] as $session)
                                    <tr>
                                        <td>#{{ $session['id'] ?? '-' }}</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-{{ ($session['probability_score'] ?? 0) >= 70 ? 'success' : (($session['probability_score'] ?? 0) >= 40 ? 'warning' : 'danger') }}" style="width: {{ $session['probability_score'] ?? 0 }}%"></div>
                                                </div>
                                                <span class="ms-2">{{ number_format($session['probability_score'] ?? 0, 1) }}%</span>
                                            </div>
                                        </td>
                                        <td>{{ $session['page_views'] ?? 0 }}</td>
                                        <td>{{ $session['time_on_site'] ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($session['outcome'] ?? '') === 'converted' ? 'success' : (($session['outcome'] ?? '') === 'abandoned' ? 'danger' : 'secondary') }} bg-soft text-{{ ($session['outcome'] ?? '') === 'converted' ? 'success' : (($session['outcome'] ?? '') === 'abandoned' ? 'danger' : 'secondary') }}">
                                                {{ ucfirst($session['outcome'] ?? 'pending') }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No session data available.</td>
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
