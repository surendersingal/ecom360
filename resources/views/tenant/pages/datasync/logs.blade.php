@extends('layouts.tenant')

@section('title', 'Data Sync — Sync Logs')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Sync Logs</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Logs</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Recent Sync Activity</h5>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
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
                                @forelse($logs as $log)
                                    <tr>
                                        <td><span class="badge bg-secondary">{{ $log->entity }}</span></td>
                                        <td><span class="badge bg-primary">{{ $log->platform }}</span></td>
                                        <td>
                                            @if($log->direction === 'inbound')
                                                <i class="bx bx-download text-success"></i> Inbound
                                            @else
                                                <i class="bx bx-upload text-info"></i> Outbound
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
                                        <td>
                                            @if($log->duration_ms)
                                                {{ number_format($log->duration_ms) }} ms
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $log->created_at->format('M d, H:i:s') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="text-center text-muted py-4">No sync activity yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $logs->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
