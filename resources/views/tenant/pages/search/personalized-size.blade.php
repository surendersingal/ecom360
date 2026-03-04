@extends('layouts.tenant')

@section('title', 'Personalized Size Filtering')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Personalized Size Filtering</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Personalized Size Filtering</li>
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
                                    <i class="bx bx-closet"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Products Found</p>
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
                                    <i class="bx bx-body"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Size Profile Matches</p>
                            <h4 class="mb-0">{{ $results['size_profile']['match_count'] ?? 0 }}</h4>
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
                                    <i class="bx bx-trending-down"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Return Rate Reduction</p>
                            <h4 class="mb-0">{{ $results['size_profile']['return_rate_reduction'] ?? '0' }}%</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">Products with Size Recommendations</h4>
                    @if(!empty($results['query']))
                        <span class="badge bg-soft-info text-info">Query: {{ $results['query'] }}</span>
                    @endif
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Available Sizes</th>
                                    <th>Recommended Size</th>
                                    <th>Fit Confidence</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['products'] ?? [] as $index => $product)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $product['name'] ?? 'N/A' }}</td>
                                        <td>{{ $product['category'] ?? 'N/A' }}</td>
                                        <td>
                                            @foreach($product['available_sizes'] ?? [] as $size)
                                                <span class="badge bg-light text-dark me-1">{{ $size }}</span>
                                            @endforeach
                                        </td>
                                        <td><span class="badge bg-primary">{{ $product['recommended_size'] ?? 'N/A' }}</span></td>
                                        <td>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: {{ ($product['fit_confidence'] ?? 0) * 100 }}%"></div>
                                            </div>
                                            <small class="text-muted">{{ number_format(($product['fit_confidence'] ?? 0) * 100, 1) }}%</small>
                                        </td>
                                        <td>${{ number_format($product['price'] ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bx bx-body fs-1 d-block mb-2"></i>
                                            No products with size recommendations available.
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
