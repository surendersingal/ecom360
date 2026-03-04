@extends('layouts.tenant')

@section('title', 'Category Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Category Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Categories</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Product Performance by Category</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr><th>Product</th><th class="text-end">Views</th><th class="text-end">Cart Adds</th><th class="text-end">Purchases</th><th class="text-end">Revenue</th><th class="text-end">View→Cart</th></tr>
                            </thead>
                            <tbody>
                                @forelse($products as $p)
                                <tr>
                                    <td>{{ $p['product_name'] ?? $p['product_id'] ?? '-' }}</td>
                                    <td class="text-end">{{ number_format($p['views'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($p['cart_adds'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($p['purchases'] ?? 0) }}</td>
                                    <td class="text-end">${{ number_format($p['revenue'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($p['view_to_cart_rate'] ?? 0, 1) }}%</td>
                                </tr>
                                @empty
                                <tr><td colspan="6" class="text-center text-muted">No category data yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection