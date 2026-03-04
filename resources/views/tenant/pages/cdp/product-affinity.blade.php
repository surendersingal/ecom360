@extends('layouts.tenant')

@section('title', 'Product Affinity Mapping')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Product Affinity Mapping</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Product Affinity Mapping</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Affinity Pairs</p>
                            <h4 class="mb-0">{{ safe_num($data['affinity_pairs'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-link-alt font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Product Clusters</p>
                            <h4 class="mb-0">{{ safe_num($data['clusters'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-scatter-chart font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Cross-Sell Opportunities</p>
                            <h4 class="mb-0">{{ safe_num($data['cross_sell_opportunities'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-cart-add font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Data Section --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Product Affinity Pairs</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product A</th>
                                    <th>Product B</th>
                                    <th>Affinity Score</th>
                                    <th>Co-Purchase Rate</th>
                                    <th>Bundle Potential</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['pairs'] ?? []) as $index => $pair)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $pair['product_a'] ?? '-' }}</td>
                                        <td>{{ $pair['product_b'] ?? '-' }}</td>
                                        <td>
                                            @php $score = $pair['affinity_score'] ?? 0; @endphp
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-{{ $score >= 70 ? 'success' : ($score >= 40 ? 'warning' : 'danger') }}" style="width: {{ $score }}%"></div>
                                                </div>
                                                <span class="ms-2">{{ $score }}%</span>
                                            </div>
                                        </td>
                                        <td>{{ number_format($pair['co_purchase_rate'] ?? 0, 1) }}%</td>
                                        <td>
                                            @php $potential = $pair['bundle_potential'] ?? 'low'; @endphp
                                            <span class="badge bg-{{ $potential === 'high' ? 'success' : ($potential === 'medium' ? 'warning' : 'secondary') }}">
                                                {{ ucfirst($potential) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No affinity pairs found.</td>
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
