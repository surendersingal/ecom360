@extends('layouts.tenant')

@section('title', 'Refund Impact Analyzer')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Refund Impact Analyzer</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Refund Impact Analyzer</li>
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
                            <p class="text-muted fw-medium">Total Refunds ($)</p>
                            <h4 class="mb-0">${{ safe_num($data['refund_total'] ?? 0, 2) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-money-withdraw font-size-24"></i>
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
                            <p class="text-muted fw-medium">Top Impact Category</p>
                            <h4 class="mb-0">{{ $data['impact_by_category'][0]['name'] ?? 'N/A' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-category font-size-24"></i>
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
                            <p class="text-muted fw-medium">Refund Rate Trend</p>
                            <h4 class="mb-0">
                                @php $trend = $data['trend'] ?? 'flat'; @endphp
                                <i class="bx bx-trending-{{ $trend === 'up' ? 'up text-danger' : ($trend === 'down' ? 'down text-success' : 'up text-muted') }}"></i>
                                {{ ucfirst($trend) }}
                            </h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-info bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info">
                                    <i class="bx bx-line-chart font-size-24"></i>
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
                    <h4 class="card-title mb-4">Refund Impact by Category</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Category</th>
                                    <th>Refund Count</th>
                                    <th>Refund Amount</th>
                                    <th>% of Revenue</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['impact_by_category'] ?? []) as $index => $category)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $category['name'] ?? '-' }}</td>
                                        <td>{{ number_format($category['refund_count'] ?? 0) }}</td>
                                        <td>${{ number_format($category['refund_amount'] ?? 0, 2) }}</td>
                                        <td>
                                            <span class="badge bg-{{ ($category['pct_of_revenue'] ?? 0) > 10 ? 'danger' : 'warning' }}">
                                                {{ number_format($category['pct_of_revenue'] ?? 0, 1) }}%
                                            </span>
                                        </td>
                                        <td>
                                            @php $catTrend = $category['trend'] ?? 'flat'; @endphp
                                            <i class="bx bx-trending-{{ $catTrend === 'up' ? 'up text-danger' : ($catTrend === 'down' ? 'down text-success' : 'up text-muted') }} font-size-18"></i>
                                            {{ ucfirst($catTrend) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">No refund data available.</td>
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
