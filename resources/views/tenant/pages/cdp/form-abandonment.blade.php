@extends('layouts.tenant')

@section('title', 'Form-Field Abandonment Heatmap')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Form-Field Abandonment Heatmap</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">CDP & Analytics</li>
                        <li class="breadcrumb-item active">Form-Field Abandonment Heatmap</li>
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
                            <p class="text-muted fw-medium">Forms Tracked</p>
                            <h4 class="mb-0">{{ safe_num($data['forms'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-primary bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bx-list-check font-size-24"></i>
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
                            <p class="text-muted fw-medium">Avg Completion Rate %</p>
                            <h4 class="mb-0">{{ safe_num($data['completion_rate'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-success bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-check-circle font-size-24"></i>
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
                            <p class="text-muted fw-medium">Highest Drop-Off Field</p>
                            <h4 class="mb-0">{{ $data['field_drop_offs'][0]['field'] ?? 'N/A' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-md rounded-circle bg-danger bg-soft mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bx-exit font-size-24"></i>
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
                    <h4 class="card-title mb-4">Form Performance</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Form Name</th>
                                    <th>Views</th>
                                    <th>Starts</th>
                                    <th>Completions</th>
                                    <th>Drop-Off Field</th>
                                    <th>Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($data['form_list'] ?? []) as $index => $form)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $form['name'] ?? '-' }}</td>
                                        <td>{{ number_format($form['views'] ?? 0) }}</td>
                                        <td>{{ number_format($form['starts'] ?? 0) }}</td>
                                        <td>{{ number_format($form['completions'] ?? 0) }}</td>
                                        <td>
                                            <span class="badge bg-danger bg-soft text-danger">
                                                {{ $form['drop_off_field'] ?? '-' }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    @php $rate = $form['completion_rate'] ?? 0; @endphp
                                                    <div class="progress-bar bg-{{ $rate >= 60 ? 'success' : ($rate >= 30 ? 'warning' : 'danger') }}" style="width: {{ $rate }}%"></div>
                                                </div>
                                                <span class="ms-2">{{ number_format($rate, 1) }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No form data available.</td>
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
