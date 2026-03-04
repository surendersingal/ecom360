@extends('layouts.tenant')

@section('title', 'AI Insights')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">AI Insights</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">AI Insights</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Forecast -->
    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Revenue Forecast (7-day)</h4>
                        <div>
                            <span class="badge bg-{{ $forecast['trend'] === 'up' ? 'success' : ($forecast['trend'] === 'down' ? 'danger' : 'warning') }} me-2">
                                Trend: {{ ucfirst($forecast['trend'] ?? 'stable') }}
                            </span>
                            <span class="badge bg-info">Confidence: {{ number_format(($forecast['confidence'] ?? 0) * 100, 0) }}%</span>
                        </div>
                    </div>
                    <div id="forecast-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Anomaly Alerts</h4>
                    @forelse($anomalies as $a)
                    <div class="alert alert-{{ $a['severity'] === 'high' ? 'danger' : ($a['severity'] === 'medium' ? 'warning' : 'info') }} mb-2">
                        <strong>{{ ucfirst($a['type'] ?? 'anomaly') }}:</strong> {{ $a['message'] ?? '' }}
                        <div class="small mt-1">{{ $a['metric'] ?? '' }}: Current {{ $a['current'] ?? 0 }} vs Avg {{ number_format($a['average'] ?? 0, 1) }}</div>
                    </div>
                    @empty
                    <div class="text-center text-muted py-4">
                        <i class="bx bx-check-circle text-success" style="font-size:36px;"></i>
                        <p class="mt-2">No anomalies detected. All metrics are within normal range.</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- AI-Generated Insights -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">AI-Generated Insights</h4>
                    <div class="row">
                        @forelse($insights as $insight)
                        <div class="col-xl-4 col-md-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bx {{ $insight['icon'] ?? 'bx-bulb' }} text-warning me-2" style="font-size:24px;"></i>
                                    <h6 class="mb-0">{{ $insight['title'] ?? 'Insight' }}</h6>
                                </div>
                                <p class="text-muted mb-1">{{ $insight['description'] ?? '' }}</p>
                                <span class="badge bg-{{ $insight['impact'] === 'high' ? 'danger' : ($insight['impact'] === 'medium' ? 'warning' : 'info') }}">
                                    Impact: {{ ucfirst($insight['impact'] ?? 'low') }}
                                </span>
                                <span class="badge bg-secondary ms-1">{{ $insight['category'] ?? '' }}</span>
                            </div>
                        </div>
                        @empty
                        <div class="col-12 text-center text-muted py-4">
                            <i class="bx bx-brain" style="font-size:48px;"></i>
                            <p class="mt-2">Not enough data to generate insights. Keep tracking to unlock AI recommendations.</p>
                        </div>
                        @endforelse
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
        $histDates = collect($forecast['historical'] ?? [])->pluck('date')->toArray();
        $histVals  = collect($forecast['historical'] ?? [])->pluck('revenue')->toArray();
        $foreDates = collect($forecast['forecast'] ?? [])->pluck('date')->toArray();
        $foreVals  = collect($forecast['forecast'] ?? [])->pluck('revenue')->toArray();
        $allDates  = array_merge($histDates, $foreDates);
        $histPadded = array_merge($histVals, array_fill(0, count($foreDates), null));
        $forePadded = array_merge(array_fill(0, count($histDates), null), $foreVals);
    @endphp
    new ApexCharts(document.querySelector("#forecast-chart"), {
        chart: { type: 'line', height: 350, toolbar: { show: false } },
        series: [
            { name: 'Historical', data: @json($histPadded) },
            { name: 'Forecast', data: @json($forePadded) }
        ],
        xaxis: { categories: @json($allDates) },
        stroke: { width: [2, 2], dashArray: [0, 5], curve: 'smooth' },
        colors: ['#556ee6', '#f46a6a'],
        dataLabels: { enabled: false }
    }).render();
</script>
@endsection