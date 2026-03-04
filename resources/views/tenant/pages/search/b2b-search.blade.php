@extends('layouts.tenant')

@section('title', 'B2B Search Gates')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">B2B Search Gates</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">B2B Search Gates</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-primary">
                                <span class="avatar-title bg-soft-primary text-primary rounded-circle fs-3">
                                    <i class="bx bx-buildings"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">B2B Products</p>
                            <h4 class="mb-0">{{ count($results['products'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-success">
                                <span class="avatar-title bg-soft-success text-success rounded-circle fs-3">
                                    <i class="bx bx-layer"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Pricing Tiers</p>
                            <h4 class="mb-0">{{ count($results['tier_pricing'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-warning">
                                <span class="avatar-title bg-soft-warning text-warning rounded-circle fs-3">
                                    <i class="bx bx-task"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">MOQ Rules Active</p>
                            <h4 class="mb-0">{{ count($results['moq_rules'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Products with Tier Pricing</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product Name</th>
                                    <th>SKU</th>
                                    <th>MOQ</th>
                                    <th>Tier 1 (1-99)</th>
                                    <th>Tier 2 (100-499)</th>
                                    <th>Tier 3 (500+)</th>
                                    <th>Lead Time</th>
                                    <th>Access Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['products'] ?? [] as $index => $product)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $product['name'] ?? 'N/A' }}</td>
                                        <td><code>{{ $product['sku'] ?? 'N/A' }}</code></td>
                                        <td><span class="badge bg-warning">{{ $product['moq'] ?? 1 }} units</span></td>
                                        <td>${{ number_format($product['tier1_price'] ?? 0, 2) }}</td>
                                        <td>${{ number_format($product['tier2_price'] ?? 0, 2) }}</td>
                                        <td class="text-success fw-semibold">${{ number_format($product['tier3_price'] ?? 0, 2) }}</td>
                                        <td>{{ $product['lead_time'] ?? 'N/A' }}</td>
                                        <td>
                                            @if(($product['access_level'] ?? '') === 'premium')
                                                <span class="badge bg-warning"><i class="bx bx-crown me-1"></i>Premium</span>
                                            @elseif(($product['access_level'] ?? '') === 'verified')
                                                <span class="badge bg-success"><i class="bx bx-check-shield me-1"></i>Verified</span>
                                            @else
                                                <span class="badge bg-secondary">Standard</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">
                                            <i class="bx bx-buildings fs-1 d-block mb-2"></i>
                                            No B2B products with tier pricing available.
                                        </td>
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
