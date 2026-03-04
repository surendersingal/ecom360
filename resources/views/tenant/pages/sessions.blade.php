@extends('layouts.tenant')

@section('title', 'Sessions Explorer')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Sessions Explorer</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Sessions</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Sessions</p>
                            <h4 class="mb-0">{{ number_format($metrics['total_sessions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-primary mini-stat-icon">
                                <span class="avatar-title"><i class="bx bx-time-five font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Bounce Rate</p>
                            <h4 class="mb-0">{{ number_format($metrics['bounce_rate'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-danger mini-stat-icon">
                                <span class="avatar-title bg-danger"><i class="bx bx-exit font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Pages / Session</p>
                            <h4 class="mb-0">{{ number_format($metrics['avg_pages_per_session'] ?? 0, 1) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-info mini-stat-icon">
                                <span class="avatar-title bg-info"><i class="bx bx-file font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Avg Duration</p>
                            <h4 class="mb-0">{{ $metrics['avg_session_duration_formatted'] ?? '0s' }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-success mini-stat-icon">
                                <span class="avatar-title bg-success"><i class="bx bx-timer font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Daily Session Trend</h4>
                    <div id="session-trend" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">New vs Returning</h4>
                    <div id="new-vs-return" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    new ApexCharts(document.querySelector("#session-trend"), {
        chart: { type: 'area', height: 350, toolbar: { show: false } },
        series: [{ name: 'Sessions', data: @json($trend['sessions'] ?? []) }],
        xaxis: { categories: @json($trend['dates'] ?? []) },
        colors: ['#556ee6'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false }
    }).render();

    new ApexCharts(document.querySelector("#new-vs-return"), {
        chart: { type: 'donut', height: 350 },
        series: [{{ $newVsReturn['new_sessions'] ?? 0 }}, {{ $newVsReturn['returning_sessions'] ?? 0 }}],
        labels: ['New ({{ number_format($newVsReturn['new_pct'] ?? 0, 1) }}%)', 'Returning ({{ number_format($newVsReturn['returning_pct'] ?? 0, 1) }}%)'],
        colors: ['#556ee6', '#34c38f'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection