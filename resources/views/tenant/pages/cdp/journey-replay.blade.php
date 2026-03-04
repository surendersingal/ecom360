@extends('layouts.tenant')

@section('title', 'Session Journey Replay')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Session Journey Replay</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Session Journey Replay</li>
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
                            <p class="text-muted fw-medium">Sessions Recorded</p>
                            <h4 class="mb-0">{{ safe_num($data['sessions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-video font-size-24"></i>
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
                            <p class="text-muted fw-medium">Avg Duration</p>
                            <h4 class="mb-0">{{ $data['avg_duration'] ?? '0:00' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-time-five font-size-24"></i>
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
                            <p class="text-muted fw-medium">Top Drop-Off Pages</p>
                            <h4 class="mb-0">{{ count($data['drop_off_points'] ?? []) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-log-out font-size-24"></i>
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
                    <h4 class="card-title mb-4">Recorded Sessions</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Session ID</th>
                                    <th>Pages Visited</th>
                                    <th>Duration</th>
                                    <th>Events</th>
                                    <th>Outcome</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['session_list'] ?? []) as $index => $session)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td><code>{{ $session['id'] ?? '-' }}</code></td>
                                        <td>{{ $session['pages_visited'] ?? 0 }}</td>
                                        <td>{{ $session['duration'] ?? '0:00' }}</td>
                                        <td>{{ $session['events'] ?? 0 }}</td>
                                        <td>
                                            @php $outcome = $session['outcome'] ?? 'bounce'; @endphp
                                            <span class="badge bg-{{ $outcome === 'converted' ? 'success' : ($outcome === 'engaged' ? 'info' : 'secondary') }}">
                                                {{ ucfirst($outcome) }}
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-primary waves-effect waves-light"
                                                    data-session-id="{{ $session['id'] ?? '' }}">
                                                <i class="bx bx-play-circle me-1"></i> Replay
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No sessions recorded.</td>
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
