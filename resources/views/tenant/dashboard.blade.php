@extends('layouts.tenant')

@section('title', 'Dashboard')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Dashboard</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item active">{{ $tenant->name }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Real-time Stats -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Live Visitors</p>
                            <h4 class="mb-0">{{ $realtime['active_sessions'] ?? 0 }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-success">
                                <span class="avatar-title bg-success"><i class="bx bx-user font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Sessions (7d)</p>
                            <h4 class="mb-0">{{ number_format($sessions['total_sessions'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-primary">
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
                            <p class="text-muted fw-medium">Total Events (7d)</p>
                            <h4 class="mb-0">{{ number_format($traffic['total_events'] ?? 0) }}</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-info">
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
                            <p class="text-muted fw-medium">Conversion Rate</p>
                            <h4 class="mb-0">{{ number_format($funnel['overall_conversion_pct'] ?? 0, 1) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="mini-stat-icon avatar-sm rounded-circle bg-warning">
                                <span class="avatar-title bg-warning"><i class="bx bx-target-lock font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Traffic Overview -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Traffic Overview (Last 7 Days)</h4>
                    <div id="traffic-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>

        <!-- Conversion Funnel -->
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Conversion Funnel</h4>
                    <div id="funnel-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Top Products -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Top Products (by Purchases)</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Count</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($topProducts as $p)
                                <tr>
                                    <td>{{ $p['product_name'] ?? $p['product_id'] ?? 'Unknown' }}</td>
                                    <td class="text-end">{{ number_format($p['count'] ?? 0) }}</td>
                                    <td class="text-end">${{ number_format($p['revenue'] ?? 0, 2) }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td class="text-muted text-center" colspan="3">
                                        <i class="bx bx-package mb-2" style="font-size:24px;"></i><br>
                                        No product data yet.
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue by Source -->
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Revenue by Source</h4>
                    <div id="revenue-channel-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Quick Navigation</h4>
                    <div class="row">
                        @php
                        $navItems = [
                            ['route' => 'tenant.realtime', 'icon' => 'bx-pulse', 'color' => 'success', 'label' => 'Real-Time'],
                            ['route' => 'tenant.page-visits', 'icon' => 'bx-file', 'color' => 'primary', 'label' => 'Page Visits'],
                            ['route' => 'tenant.products', 'icon' => 'bx-package', 'color' => 'warning', 'label' => 'Products'],
                            ['route' => 'tenant.funnels', 'icon' => 'bx-filter-alt', 'color' => 'info', 'label' => 'Funnels'],
                            ['route' => 'tenant.ai-insights', 'icon' => 'bx-brain', 'color' => 'danger', 'label' => 'AI Insights'],
                            ['route' => 'tenant.integration', 'icon' => 'bx-code-alt', 'color' => 'secondary', 'label' => 'Integration'],
                        ];
                        @endphp
                        @foreach($navItems as $nav)
                        <div class="col-xl-2 col-md-4 col-6 mb-3">
                            <a href="{{ route($nav['route'], $tenant->slug) }}" class="text-center d-block p-3 border rounded">
                                <i class="bx {{ $nav['icon'] }} text-{{ $nav['color'] }} mb-2" style="font-size:28px;"></i>
                                <p class="mb-0">{{ $nav['label'] }}</p>
                            </a>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    // Traffic Overview Chart
    new ApexCharts(document.querySelector("#traffic-chart"), {
        chart: { type: 'area', height: 350, toolbar: { show: false } },
        series: [{ name: 'Sessions', data: @json($dailySessions['sessions'] ?? []) }],
        xaxis: { categories: @json($dailySessions['dates'] ?? []) },
        colors: ['#556ee6'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false }
    }).render();

    // Funnel Chart
    @php
        $funnelLabels = collect($funnel['stages'] ?? [])->pluck('stage')->toArray();
        $funnelData   = collect($funnel['stages'] ?? [])->pluck('unique_sessions')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#funnel-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        plotOptions: { bar: { horizontal: true, barHeight: '60%' } },
        series: [{ name: 'Users', data: @json($funnelData) }],
        xaxis: { categories: @json($funnelLabels) },
        colors: ['#556ee6'],
        dataLabels: { enabled: true }
    }).render();

    // Revenue by Source
    @php
        $revLabels = collect($revBySource)->pluck('source')->toArray();
        $revValues = collect($revBySource)->pluck('revenue')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#revenue-channel-chart"), {
        chart: { type: 'donut', height: 300 },
        series: @json(count($revValues) ? $revValues : [0]),
        labels: @json(count($revLabels) ? $revLabels : ['No Data Yet']),
        colors: count(@json($revValues)) > 0 ? ['#556ee6','#34c38f','#f46a6a','#50a5f1','#f1b44c'] : ['#e0e0e0'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection