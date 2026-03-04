@extends('layouts.admin')

@section('title', 'Platform Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Platform Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Platform Analytics</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Active Stores</p>
                            <h4 class="mb-0">{{ $tenants->count() }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-primary mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary"><i class="bx bx-store font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Total Events (30d)</p>
                            <h4 class="mb-0">{{ number_format($platformStats['total_events'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-success mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success"><i class="bx bx-pulse font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Total Sessions (30d)</p>
                            <h4 class="mb-0">{{ number_format($platformStats['total_sessions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-warning mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning"><i class="bx bx-bar-chart font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Avg Events / Store</p>
                            <h4 class="mb-0">{{ $tenants->count() > 0 ? number_format(($platformStats['total_events'] ?? 0) / $tenants->count()) : 0 }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-info mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-info"><i class="bx bx-server font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Events by Store (30d)</h4>
                    <div id="events-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Sessions Distribution</h4>
                    <div id="stores-donut" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Per-Tenant Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Analytics Overview</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr><th>Store</th><th class="text-end">Events</th><th class="text-end">Sessions</th><th class="text-end">Bounce Rate</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                @foreach($perTenant as $pt)
                                <tr>
                                    <td>{{ $pt['name'] }}</td>
                                    <td class="text-end">{{ number_format($pt['events']) }}</td>
                                    <td class="text-end">{{ number_format($pt['sessions']) }}</td>
                                    <td class="text-end">{{ number_format($pt['bounce'], 1) }}%</td>
                                    <td><a href="{{ route('admin.impersonate.start', $pt['slug']) }}" class="btn btn-sm btn-soft-primary"><i class="bx bx-log-in-circle me-1"></i>View</a></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $ptNames  = collect($perTenant)->pluck('name')->toArray();
        $ptEvents = collect($perTenant)->pluck('events')->toArray();
        $ptSess   = collect($perTenant)->pluck('sessions')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#events-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [
            { name: 'Events', data: @json($ptEvents) },
            { name: 'Sessions', data: @json($ptSess) }
        ],
        xaxis: { categories: @json($ptNames) },
        colors: ['#556ee6', '#34c38f'],
        plotOptions: { bar: { columnWidth: '50%' } },
        dataLabels: { enabled: false }
    }).render();

    new ApexCharts(document.querySelector("#stores-donut"), {
        chart: { type: 'donut', height: 350 },
        series: @json(count($ptSess) ? $ptSess : [0]),
        labels: @json(count($ptNames) ? $ptNames : ['No Data']),
        colors: ['#556ee6','#34c38f','#f1b44c','#f46a6a','#50a5f1'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection
