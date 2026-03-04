@extends('layouts.tenant')

@section('title', 'Data Sync — Permissions')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Sync Permissions</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Permissions</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="bx bx-info-circle me-1"></i>
                Permissions are managed from your Magento module or WooCommerce plugin settings. Changes made there will automatically reflect here.
            </div>
        </div>
    </div>

    @forelse($connections as $conn)
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            {{ $conn->store_name ?? $conn->store_url }}
                            <small class="text-muted">({{ ucfirst($conn->platform->value) }})</small>
                        </h5>
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Entity</th>
                                        <th>Consent Level</th>
                                        <th>Status</th>
                                        <th>Granted At</th>
                                        <th>Granted By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($conn->permissions as $perm)
                                        <tr>
                                            <td>
                                                <i class="bx {{ $perm->entity->value === 'products' ? 'bx-package' : ($perm->entity->value === 'orders' ? 'bx-receipt' : ($perm->entity->value === 'customers' ? 'bx-user' : 'bx-data')) }} me-1"></i>
                                                {{ ucfirst(str_replace('_', ' ', $perm->entity->value)) }}
                                            </td>
                                            <td>
                                                @php $level = $perm->consent_level->value; @endphp
                                                <span class="badge bg-{{ $level === 'public' ? 'success' : ($level === 'restricted' ? 'warning' : 'danger') }}">
                                                    {{ ucfirst($level) }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($perm->enabled)
                                                    <span class="badge bg-success"><i class="bx bx-check"></i> Enabled</span>
                                                @else
                                                    <span class="badge bg-secondary"><i class="bx bx-x"></i> Disabled</span>
                                                @endif
                                            </td>
                                            <td>{{ $perm->granted_at?->diffForHumans() ?? '—' }}</td>
                                            <td>{{ $perm->granted_by ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bx bx-lock-open-alt font-size-48 text-muted mb-3 d-block"></i>
                        <h5>No Connections</h5>
                        <p class="text-muted">Connect a store first to manage sync permissions.</p>
                    </div>
                </div>
            </div>
        </div>
    @endforelse
@endsection
