@extends('layouts.tenant')

@section('title', 'Feature Comparison Matrix')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Feature Comparison Matrix</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Feature Comparison Matrix</li>
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
                                    <i class="bx bx-columns"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Products Compared</p>
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
                                    <i class="bx bx-list-ul"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Features Analyzed</p>
                            <h4 class="mb-0">{{ count($results['comparison_matrix'] ?? []) }}</h4>
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
                                    <i class="bx bx-bulb"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">AI Recommendation</p>
                            <h4 class="mb-0 text-truncate" style="max-width: 180px;" title="{{ $results['recommendation']['product_name'] ?? 'N/A' }}">
                                {{ $results['recommendation']['product_name'] ?? 'N/A' }}
                            </h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($results['recommendation']))
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="bx bx-bulb fs-4 me-2"></i>
                    <div>
                        <strong>AI Recommendation:</strong> {{ $results['recommendation']['reason'] ?? 'Based on the comparison, we recommend the highlighted product.' }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Comparison Matrix</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="bg-light" style="min-width: 180px;">Feature</th>
                                    @forelse($results['products'] ?? [] as $product)
                                        <th class="text-center {{ ($results['recommendation']['product_id'] ?? null) === ($product['id'] ?? null) ? 'bg-soft-success' : '' }}">
                                            <div class="fw-semibold">{{ $product['name'] ?? 'N/A' }}</div>
                                            <small class="text-muted">${{ number_format($product['price'] ?? 0, 2) }}</small>
                                            @if(($results['recommendation']['product_id'] ?? null) === ($product['id'] ?? null))
                                                <div><span class="badge bg-success mt-1"><i class="bx bx-star me-1"></i>Recommended</span></div>
                                            @endif
                                        </th>
                                    @empty
                                        <th class="text-center text-muted">No products to compare</th>
                                    @endforelse
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['comparison_matrix'] ?? [] as $feature)
                                    <tr>
                                        <td class="fw-semibold bg-light">{{ $feature['name'] ?? 'N/A' }}</td>
                                        @foreach($results['products'] ?? [] as $product)
                                            @php
                                                $value = $feature['values'][$product['id'] ?? $loop->index] ?? null;
                                                $isBest = ($feature['best'] ?? null) === ($product['id'] ?? $loop->index);
                                            @endphp
                                            <td class="text-center {{ $isBest ? 'bg-soft-success' : '' }}">
                                                @if(is_bool($value))
                                                    @if($value)
                                                        <i class="bx bx-check-circle text-success fs-4"></i>
                                                    @else
                                                        <i class="bx bx-x-circle text-danger fs-4"></i>
                                                    @endif
                                                @else
                                                    {{ $value ?? 'N/A' }}
                                                    @if($isBest)
                                                        <i class="bx bx-trophy text-warning ms-1"></i>
                                                    @endif
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($results['products'] ?? []) + 1 }}" class="text-center text-muted py-4">
                                            <i class="bx bx-columns fs-1 d-block mb-2"></i>
                                            No comparison data available. Select products to compare.
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
