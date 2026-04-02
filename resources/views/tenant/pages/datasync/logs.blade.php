@extends('layouts.tenant')

@section('title', 'Data Sync — Sync Logs')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Sync Logs</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">DataSync</li>
                    <li class="breadcrumb-item active">Logs</li>
                </ol>
            </nav>
        </div>
        <div class="header-actions">
            <span style="font-size:13px;color:var(--neutral-400);">{{ $logs->total() }} {{ Str::plural('record', $logs->total()) }}</span>
        </div>
    </div>

    <div class="card" data-module="datasync">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-nowrap mb-0">
                    <thead>
                        <tr>
                            <th>Entity</th>
                            <th>Platform</th>
                            <th>Direction</th>
                            <th>Status</th>
                            <th class="text-end">Received</th>
                            <th class="text-end">Created</th>
                            <th class="text-end">Updated</th>
                            <th class="text-end">Failed</th>
                            <th class="text-end">Duration</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            @php
                                $statusClass = match($log->status) {
                                    'completed' => 'e360-badge-active',
                                    'partial'   => 'e360-badge-pending',
                                    'failed'    => 'e360-badge-error',
                                    'running'   => 'e360-badge-info',
                                    default     => '',
                                };
                                $hasFailed = ($log->records_failed ?? 0) > 0;
                            @endphp
                            <tr style="{{ $hasFailed ? 'background:var(--danger-bg);' : '' }}">
                                <td><span class="e360-badge" style="background:var(--surface-2);color:var(--neutral-700);">{{ $log->entity }}</span></td>
                                <td><span class="e360-badge" style="background:var(--primary-100);color:var(--primary-700);">{{ $log->platform }}</span></td>
                                <td>
                                    @if($log->direction === 'inbound')
                                        <i class="bx bx-download" style="color:var(--success);margin-right:4px;"></i>
                                        <span style="font-size:13px;color:var(--neutral-700);">Inbound</span>
                                    @else
                                        <i class="bx bx-upload" style="color:var(--info);margin-right:4px;"></i>
                                        <span style="font-size:13px;color:var(--neutral-700);">Outbound</span>
                                    @endif
                                </td>
                                <td><span class="e360-badge {{ $statusClass }}">{{ ucfirst($log->status) }}</span></td>
                                <td class="text-end mono">{{ number_format($log->records_received ?? 0) }}</td>
                                <td class="text-end mono" style="color:var(--success);font-weight:500;">{{ number_format($log->records_created ?? 0) }}</td>
                                <td class="text-end mono" style="color:var(--info);font-weight:500;">{{ number_format($log->records_updated ?? 0) }}</td>
                                <td class="text-end mono" style="{{ $hasFailed ? 'color:var(--danger);font-weight:700;' : '' }}">{{ number_format($log->records_failed ?? 0) }}</td>
                                <td class="text-end mono" style="color:var(--neutral-500);font-size:12px;">
                                    @if($log->duration_ms)
                                        {{ number_format($log->duration_ms) }}ms
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="font-size:13px;color:var(--neutral-500);white-space:nowrap;">{{ $log->created_at->format('M d, H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10">
                                    <div class="e360-empty-state" style="padding:32px 0;">
                                        <div class="empty-icon">📋</div>
                                        <h3>No sync activity yet</h3>
                                        <p>Sync logs will appear here once data starts flowing from your connected stores.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($logs->hasPages())
            <div class="mt-4 d-flex justify-content-center">{{ $logs->links() }}</div>
            @endif
        </div>
    </div>
@endsection
