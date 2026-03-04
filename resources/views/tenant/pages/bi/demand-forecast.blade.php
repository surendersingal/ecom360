@extends('layouts.tenant')

@section('title', 'Demand Forecasting')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Demand Forecasting</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Business Intelligence</li>
                        <li class="breadcrumb-item active">Demand Forecasting</li>
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
                            <p class="text-muted fw-medium">Products Forecasted</p>
                            <h4 class="mb-0">{{ number_format(count($data['forecasts'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-trending-up font-size-24"></i>
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
                            <p class="text-muted fw-medium">Avg Confidence</p>
                            <h4 class="mb-0">{{ safe_num($data['confidence'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-check-shield font-size-24"></i>
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
                            <p class="text-muted fw-medium">Recommendations</p>
                            <h4 class="mb-0">{{ number_format(count($data['recommendations'] ?? [])) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-warning bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-bulb font-size-24"></i>
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
                    <h4 class="card-title mb-4">Product Demand Forecasts</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product Name</th>
                                    <th>Predicted Demand</th>
                                    <th>Confidence</th>
                                    <th>Recommendation</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($data['forecasts'] ?? [] as $forecast)
                                    <tr>
                                        <td>{{ $forecast['name'] ?? '-' }}</td>
                                        <td>{{ number_format($forecast['predicted_demand'] ?? 0) }}</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-{{ ($forecast['confidence'] ?? 0) >= 80 ? 'success' : (($forecast['confidence'] ?? 0) >= 50 ? 'warning' : 'danger') }}" style="width: {{ $forecast['confidence'] ?? 0 }}%"></div>
                                                </div>
                                                <span class="ms-2">{{ number_format($forecast['confidence'] ?? 0, 1) }}%</span>
                                            </div>
                                        </td>
                                        <td>{{ $forecast['recommendation'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">No demand forecasts available.</td>
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
