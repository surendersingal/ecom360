@extends('layouts.tenant')

@section('title', 'Real-Time Traffic')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Real-Time Traffic</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Real-Time</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Active Sessions</p>
                            <h4 class="mb-0">{{ $rt['active_sessions'] ?? 0 }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <span class="badge bg-success rounded-pill px-3 py-2">Live</span>
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
                            <p class="text-muted fw-medium">Events / Minute</p>
                            <h4 class="mb-0">{{ $rt['events_per_minute'] ?? 0 }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-primary mini-stat-icon">
                                <span class="avatar-title"><i class="bx bx-pulse font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Top Pages</p>
                            <h4 class="mb-0">{{ count($rt['top_pages'] ?? []) }}</h4>
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
                            <p class="text-muted fw-medium">Countries</p>
                            <h4 class="mb-0">{{ count($rt['geo_breakdown'] ?? []) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-warning mini-stat-icon">
                                <span class="avatar-title bg-warning"><i class="bx bx-globe font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Active Pages</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead class="table-light">
                                <tr><th>Page URL</th><th class="text-end">Active Users</th></tr>
                            </thead>
                            <tbody>
                                @forelse($rt['top_pages'] ?? [] as $page)
                                <tr>
                                    <td class="text-truncate" style="max-width:300px;">{{ $page['url'] ?? $page['page'] ?? '-' }}</td>
                                    <td class="text-end">{{ $page['count'] ?? $page['active'] ?? 0 }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="2" class="text-center text-muted">No active pages</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Geographic Breakdown</h4>
                    <div id="geo-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $geoLabels = collect($rt['geo_breakdown'] ?? [])->pluck('country')->take(10)->toArray();
        $geoValues = collect($rt['geo_breakdown'] ?? [])->pluck('count')->take(10)->toArray();
    @endphp
    new ApexCharts(document.querySelector("#geo-chart"), {
        chart: { type: 'bar', height: 300, toolbar: { show: false } },
        series: [{ name: 'Visitors', data: @json(count($geoValues) ? $geoValues : [0]) }],
        xaxis: { categories: @json(count($geoLabels) ? $geoLabels : ['No Data']) },
        plotOptions: { bar: { horizontal: true } },
        colors: ['#556ee6'],
        dataLabels: { enabled: true }
    }).render();
</script>
@endsection