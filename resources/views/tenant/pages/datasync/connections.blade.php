@extends('layouts.tenant')

@section('title', 'Data Sync — Connections')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Connected Stores</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">DataSync</li>
                    <li class="breadcrumb-item active">Connections</li>
                </ol>
            </nav>
        </div>
        <div class="header-actions">
            <span class="e360-badge e360-badge-active" style="font-size:13px;">
                <span class="e360-badge-live"></span>
                {{ $connections->count() }} {{ Str::plural('Store', $connections->count()) }}
            </span>
        </div>
    </div>

    @if($connections->isEmpty())
        <div class="card" data-module="datasync">
            <div class="card-body">
                <div class="e360-empty-state">
                    <div class="empty-icon">🔌</div>
                    <h3>No Connections Yet</h3>
                    <p>Install the Ecom360 module on your Magento or WooCommerce store, enter your API key & secret, and the connection will appear here automatically.</p>
                    <div class="d-flex justify-content-center gap-4 mt-4">
                        <div style="padding:20px 24px;border:1px solid var(--border);border-radius:12px;text-align:center;min-width:160px;transition:all 200ms ease;cursor:default;" onmouseover="this.style.borderColor='var(--warning)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--border)';this.style.transform='none'">
                            <i class="bx bxl-magento" style="font-size:32px;color:var(--warning);display:block;margin-bottom:8px;"></i>
                            <span style="font-weight:600;color:var(--neutral-800);">Magento 2</span>
                        </div>
                        <div style="padding:20px 24px;border:1px solid var(--border);border-radius:12px;text-align:center;min-width:160px;transition:all 200ms ease;cursor:default;" onmouseover="this.style.borderColor='var(--primary-500)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--border)';this.style.transform='none'">
                            <i class="bx bxl-wordpress" style="font-size:32px;color:var(--primary-500);display:block;margin-bottom:8px;"></i>
                            <span style="font-weight:600;color:var(--neutral-800);">WooCommerce</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row g-3">
            @foreach($connections as $conn)
                <div class="col-xl-4 col-md-6">
                    <div class="card connection-card" data-module="datasync" style="height:100%;">
                        <div class="card-body d-flex flex-column">
                            {{-- Header --}}
                            <div class="d-flex align-items-start mb-3">
                                <div style="width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                    @if($conn->platform->value === 'magento2') background:var(--warning-bg);color:var(--warning);
                                    @elseif($conn->platform->value === 'woocommerce') background:var(--primary-100);color:var(--primary-500);
                                    @else background:var(--surface-2);color:var(--neutral-500);
                                    @endif">
                                    @if($conn->platform->value === 'magento2')
                                        <i class="bx bxl-magento" style="font-size:22px;"></i>
                                    @elseif($conn->platform->value === 'woocommerce')
                                        <i class="bx bxl-wordpress" style="font-size:22px;"></i>
                                    @else
                                        <i class="bx bx-store" style="font-size:22px;"></i>
                                    @endif
                                </div>
                                <div class="ms-3 flex-grow-1 min-w-0">
                                    <h5 class="mb-0" style="font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $conn->store_name ?? $conn->store_url }}</h5>
                                    <span style="font-size:12px;color:var(--neutral-400);">{{ ucfirst($conn->platform->value) }}</span>
                                </div>
                                <span class="e360-badge {{ $conn->is_active ? 'e360-badge-active' : 'e360-badge-error' }} ms-2">
                                    @if($conn->is_active)<span class="e360-badge-live"></span>@endif
                                    {{ $conn->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>

                            {{-- Details --}}
                            <div style="flex:1;display:flex;flex-direction:column;gap:8px;font-size:13px;margin-bottom:16px;">
                                <div class="d-flex justify-content-between">
                                    <span style="color:var(--neutral-400);">Store URL</span>
                                    <span class="mono" style="color:var(--neutral-700);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:right;">{{ $conn->store_url }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span style="color:var(--neutral-400);">Platform</span>
                                    <span style="color:var(--neutral-700);">{{ $conn->platform_version ?? '—' }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span style="color:var(--neutral-400);">Module</span>
                                    <span class="mono" style="color:var(--neutral-700);">{{ $conn->module_version ?? '—' }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span style="color:var(--neutral-400);">Locale / Currency</span>
                                    <span style="color:var(--neutral-700);">{{ $conn->locale ?? 'en_US' }} / {{ $conn->currency ?? 'USD' }}</span>
                                </div>
                            </div>

                            {{-- Footer --}}
                            <div style="padding-top:12px;border-top:1px solid var(--border);font-size:12px;display:flex;align-items:center;justify-content:space-between;">
                                <span style="color:var(--neutral-400);">Last heartbeat</span>
                                <span style="color:var(--neutral-600);font-weight:500;">{{ $conn->last_heartbeat_at?->diffForHumans() ?? 'Never' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
