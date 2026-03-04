@extends('layouts.tenant')

@section('title', 'Data Sync — Connections')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Connected Stores</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Connections</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    @if($connections->isEmpty())
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bx bx-plug font-size-48 text-muted mb-3 d-block"></i>
                        <h5>No Connections Yet</h5>
                        <p class="text-muted mb-4">Install the Ecom360 module on your Magento or WooCommerce store, enter your API key &amp; secret, and the connection will appear here automatically.</p>
                        <div class="d-flex justify-content-center gap-3">
                            <div class="border rounded p-3 text-center" style="width:200px">
                                <i class="bx bxl-magento font-size-24 text-primary"></i>
                                <p class="mt-2 mb-0 fw-medium">Magento 2</p>
                            </div>
                            <div class="border rounded p-3 text-center" style="width:200px">
                                <i class="bx bxl-wordpress font-size-24 text-primary"></i>
                                <p class="mt-2 mb-0 fw-medium">WooCommerce</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row">
            @foreach($connections as $conn)
                <div class="col-xl-4 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                @if($conn->platform->value === 'magento2')
                                    <i class="bx bxl-magento font-size-24 text-warning me-2"></i>
                                @elseif($conn->platform->value === 'woocommerce')
                                    <i class="bx bxl-wordpress font-size-24 text-primary me-2"></i>
                                @else
                                    <i class="bx bx-store font-size-24 text-secondary me-2"></i>
                                @endif
                                <div>
                                    <h5 class="mb-0">{{ $conn->store_name ?? $conn->store_url }}</h5>
                                    <small class="text-muted">{{ ucfirst($conn->platform->value) }}</small>
                                </div>
                                <span class="badge bg-{{ $conn->is_active ? 'success' : 'danger' }} ms-auto">{{ $conn->is_active ? 'Active' : 'Inactive' }}</span>
                            </div>
                            <table class="table table-sm table-nowrap mb-0">
                                <tr><th>URL:</th><td class="text-truncate" style="max-width:200px">{{ $conn->store_url }}</td></tr>
                                <tr><th>Platform:</th><td>{{ $conn->platform_version ?? '—' }}</td></tr>
                                <tr><th>Module:</th><td>{{ $conn->module_version ?? '—' }}</td></tr>
                                <tr><th>Locale:</th><td>{{ $conn->locale ?? 'en_US' }} / {{ $conn->currency ?? 'USD' }}</td></tr>
                                <tr><th>Last Heartbeat:</th><td>{{ $conn->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
