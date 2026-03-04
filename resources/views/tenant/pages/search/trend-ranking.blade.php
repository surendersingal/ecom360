@extends('layouts.tenant')

@section('title', 'Trend-Injected Ranking')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Trend-Injected Ranking</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Trend-Injected Ranking</li>
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
                                    <i class="bx bx-trending-up"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Trending Products</p>
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
                                    <i class="bx bx-pulse"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Social Signals</p>
                            <h4 class="mb-0">{{ is_array($results['social_mentions'] ?? []) ? count($results['social_mentions'] ?? []) : (int) ($results['social_mentions'] ?? 0) }}</h4>
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
                                    <i class="bx bx-rocket"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Ranking Boosts</p>
                            <h4 class="mb-0">{{ count($results['trending_signals'] ?? []) }}</h4>
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
                    <h4 class="card-title mb-0">Products with Trend Scores</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Trend Score</th>
                                    <th>Social Mentions</th>
                                    <th>Boost Factor</th>
                                    <th>Trend Source</th>
                                    <th>Velocity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['products'] ?? [] as $index => $product)
                                    <tr>
                                        <td>
                                            @if($index < 3)
                                                <span class="badge bg-{{ $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'danger') }} rounded-pill">
                                                    #{{ $index + 1 }}
                                                </span>
                                            @else
                                                {{ $index + 1 }}
                                            @endif
                                        </td>
                                        <td>{{ $product['name'] ?? 'N/A' }}</td>
                                        <td>{{ $product['category'] ?? 'N/A' }}</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: {{ min(($product['trend_score'] ?? 0), 100) }}%"></div>
                                                </div>
                                                <small class="fw-semibold">{{ $product['trend_score'] ?? 0 }}</small>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="bx bx-message-rounded-dots text-info me-1"></i>
                                            {{ number_format($product['social_mentions'] ?? 0) }}
                                        </td>
                                        <td>
                                            <span class="text-success fw-semibold">
                                                <i class="bx bx-up-arrow-alt"></i>{{ number_format($product['boost_factor'] ?? 1, 2) }}x
                                            </span>
                                        </td>
                                        <td>
                                            @foreach($product['trend_sources'] ?? [] as $source)
                                                <span class="badge bg-soft-primary text-primary me-1">{{ $source }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            @if(($product['velocity'] ?? '') === 'rising')
                                                <span class="text-success"><i class="bx bx-up-arrow-alt"></i> Rising</span>
                                            @elseif(($product['velocity'] ?? '') === 'stable')
                                                <span class="text-warning"><i class="bx bx-minus"></i> Stable</span>
                                            @else
                                                <span class="text-danger"><i class="bx bx-down-arrow-alt"></i> Cooling</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bx bx-trending-up fs-1 d-block mb-2"></i>
                                            No trending product data available.
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
