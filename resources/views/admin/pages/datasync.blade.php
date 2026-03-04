@extends('layouts.admin')

@section('title', 'Data Sync — Overview')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Data Sync Overview</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Data Sync</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row">
        <div class="col-md-3">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Connections</p>
                            <h4 class="mb-0">{{ $connections->count() }}</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center">
                            <span class="avatar-title"><i class="bx bx-plug font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Active Connections</p>
                            <h4 class="mb-0">{{ $connections->where('is_active', true)->count() }}</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center">
                            <span class="avatar-title"><i class="bx bx-check-circle font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Permissions</p>
                            <h4 class="mb-0">{{ $connections->sum(fn ($c) => $c->permissions->count()) }}</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-info align-self-center">
                            <span class="avatar-title"><i class="bx bx-lock-open-alt font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Recent Syncs (50)</p>
                            <h4 class="mb-0">{{ $recentLogs->where('status', 'completed')->count() }} OK / {{ $recentLogs->where('status', 'failed')->count() }} Failed</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center">
                            <span class="avatar-title"><i class="bx bx-history font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Connections --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">All Connections</h5>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tenant</th>
                                    <th>Platform</th>
                                    <th>Store URL</th>
                                    <th>Active</th>
                                    <th>Permissions</th>
                                    <th>Last Heartbeat</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($connections as $conn)
                                    <tr>
                                        <td><span class="badge bg-dark">{{ $conn->tenant_id }}</span></td>
                                        <td>
                                            @if(str_contains(strtolower($conn->platform->value ?? $conn->platform), 'magento'))
                                                <i class="bx bxl-magento text-warning"></i>
                                            @else
                                                <i class="bx bxl-wordpress text-primary"></i>
                                            @endif
                                            {{ $conn->platform instanceof \Modules\DataSync\Enums\Platform ? $conn->platform->label() : ucfirst($conn->platform) }}
                                        </td>
                                        <td><code>{{ $conn->store_url }}</code></td>
                                        <td><span class="badge bg-{{ $conn->is_active ? 'success' : 'secondary' }}">{{ $conn->is_active ? 'Active' : 'Inactive' }}</span></td>
                                        <td>{{ $conn->permissions->count() }} rules</td>
                                        <td>{{ $conn->last_heartbeat_at ? $conn->last_heartbeat_at->diffForHumans() : '—' }}</td>
                                        <td>{{ $conn->created_at->format('M d, Y') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No connections registered.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Sync Logs --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Recent Sync Logs (Last 50)</h5>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tenant</th>
                                    <th>Entity</th>
                                    <th>Platform</th>
                                    <th>Direction</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                    <th>Failed</th>
                                    <th>Duration</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentLogs as $log)
                                    <tr>
                                        <td><span class="badge bg-dark">{{ $log->tenant_id }}</span></td>
                                        <td><span class="badge bg-secondary">{{ $log->entity }}</span></td>
                                        <td><span class="badge bg-primary">{{ $log->platform }}</span></td>
                                        <td>
                                            @if($log->direction === 'inbound')
                                                <i class="bx bx-download text-success"></i> In
                                            @else
                                                <i class="bx bx-upload text-info"></i> Out
                                            @endif
                                        </td>
                                        <td>
                                            @php
                                                $statusColor = match($log->status) {
                                                    'completed' => 'success',
                                                    'partial'   => 'warning',
                                                    'failed'    => 'danger',
                                                    'running'   => 'info',
                                                    default     => 'secondary',
                                                };
                                            @endphp
                                            <span class="badge bg-{{ $statusColor }}">{{ ucfirst($log->status) }}</span>
                                        </td>
                                        <td>{{ number_format($log->records_received ?? 0) }}</td>
                                        <td class="text-success">{{ number_format($log->records_created ?? 0) }}</td>
                                        <td class="text-info">{{ number_format($log->records_updated ?? 0) }}</td>
                                        <td class="{{ ($log->records_failed ?? 0) > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($log->records_failed ?? 0) }}</td>
                                        <td>{{ $log->duration_ms ? number_format($log->duration_ms).' ms' : '—' }}</td>
                                        <td>{{ $log->created_at->format('M d, H:i:s') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="11" class="text-center text-muted py-4">No sync activity yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
