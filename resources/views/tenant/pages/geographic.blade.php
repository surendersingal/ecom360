@extends('layouts.tenant')

@section('title', 'Geographic Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Geographic Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Geographic</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Total Sessions</p>
                        <h4 class="mb-0">{{ number_format($devices['total_sessions'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Countries</p>
                        <h4 class="mb-0">{{ $countryData['total_countries'] ?? count($countries) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Desktop</p>
                        <h4 class="mb-0">{{ number_format($devices['devices']['desktop'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Mobile</p>
                        <h4 class="mb-0">{{ number_format($devices['devices']['mobile'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Visitors by Country</h4>
                    <div id="country-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Device Breakdown</h4>
                    <div id="device-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Top Cities</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead class="table-light"><tr><th>City</th><th>Country</th><th class="text-end">Sessions</th></tr></thead>
                            <tbody>
                                @forelse($cities as $c)
                                <tr><td>{{ $c['city'] ?? '-' }}</td><td>{{ $c['country'] ?? '-' }}</td><td class="text-end">{{ number_format($c['sessions'] ?? 0) }}</td></tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted">No city data</td></tr>
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
                    <h4 class="card-title mb-4">Traffic by Hour</h4>
                    <div id="hourly-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $cNames = collect($countries)->pluck('country')->take(10)->toArray();
        $cSess  = collect($countries)->pluck('sessions')->take(10)->toArray();
        $devArr = $devices['devices'] ?? ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
    @endphp
    new ApexCharts(document.querySelector("#country-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [{ name: 'Sessions', data: @json(count($cSess) ? $cSess : [0]) }],
        xaxis: { categories: @json(count($cNames) ? $cNames : ['No Data']) },
        plotOptions: { bar: { horizontal: true } },
        colors: ['#556ee6']
    }).render();

    new ApexCharts(document.querySelector("#device-chart"), {
        chart: { type: 'donut', height: 350 },
        series: [{{ $devArr['desktop'] ?? 0 }}, {{ $devArr['mobile'] ?? 0 }}, {{ $devArr['tablet'] ?? 0 }}, {{ $devArr['other'] ?? 0 }}],
        labels: ['Desktop', 'Mobile', 'Tablet', 'Other'],
        colors: ['#556ee6', '#34c38f', '#f1b44c', '#50a5f1'],
        legend: { position: 'bottom' }
    }).render();

    new ApexCharts(document.querySelector("#hourly-chart"), {
        chart: { type: 'bar', height: 300, toolbar: { show: false } },
        series: [{ name: 'Page Views', data: @json($hourly['views'] ?? []) }],
        xaxis: { categories: @json($hourly['hours'] ?? []) },
        colors: ['#50a5f1'],
        dataLabels: { enabled: false }
    }).render();
</script>
@endsection