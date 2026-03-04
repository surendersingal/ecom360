@extends('layouts.tenant')

@section('title', 'Out-of-Stock Smart Rerouting')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Out-of-Stock Smart Rerouting</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Out-of-Stock Smart Rerouting</li>
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
                            <div class="avatar-md rounded-circle bg-soft-danger">
                                <span class="avatar-title bg-soft-danger text-danger rounded-circle fs-3">
                                    <i class="bx bx-x-circle"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">OOS Products</p>
                            <h4 class="mb-0">{{ count($results['original_product'] ?? []) }}</h4>
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
                                    <i class="bx bx-transfer-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Alternatives Offered</p>
                            <h4 class="mb-0">{{ count($results['alternatives'] ?? []) }}</h4>
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
                            <div class="avatar-md rounded-circle bg-soft-info">
                                <span class="avatar-title bg-soft-info text-info rounded-circle fs-3">
                                    <i class="bx bx-time-five"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Restock ETAs</p>
                            <h4 class="mb-0">{{ count($results['restock_estimate'] ?? []) }}</h4>
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
                    <h4 class="card-title mb-0">Rerouted Products & Alternatives</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Original Product</th>
                                    <th>Alternative Product</th>
                                    <th>Similarity</th>
                                    <th>Price Diff</th>
                                    <th>Restock ETA</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['alternatives'] ?? [] as $index => $alt)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>
                                            <span class="text-decoration-line-through text-muted">{{ $alt['original_name'] ?? 'N/A' }}</span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold">{{ $alt['alternative_name'] ?? 'N/A' }}</span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: {{ ($alt['similarity'] ?? 0) * 100 }}%"></div>
                                            </div>
                                            <small class="text-muted">{{ number_format(($alt['similarity'] ?? 0) * 100, 1) }}%</small>
                                        </td>
                                        <td>
                                            @php $diff = ($alt['price_diff'] ?? 0); @endphp
                                            <span class="{{ $diff >= 0 ? 'text-danger' : 'text-success' }}">
                                                {{ $diff >= 0 ? '+' : '' }}${{ number_format($diff, 2) }}
                                            </span>
                                        </td>
                                        <td>{{ $alt['restock_eta'] ?? 'Unknown' }}</td>
                                        <td>
                                            @if(($alt['accepted'] ?? false))
                                                <span class="badge bg-success">Accepted</span>
                                            @else
                                                <span class="badge bg-warning">Pending</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bx bx-transfer-alt fs-1 d-block mb-2"></i>
                                            No out-of-stock rerouting data available.
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
