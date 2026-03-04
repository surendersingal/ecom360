@extends('layouts.tenant')

@section('title', 'Customer Journey')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Customer Journey</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Customer Journey</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <p class="text-muted fw-medium">Total Customers</p>
                    <h4 class="mb-0">{{ number_format($cohorts['total_customers'] ?? 0) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <p class="text-muted fw-medium">Repeat Buyers</p>
                    <h4 class="mb-0">{{ number_format($cohorts['repeat_customers'] ?? 0) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <p class="text-muted fw-medium">Repeat Rate</p>
                    <h4 class="mb-0">{{ number_format($cohorts['repeat_purchase_rate'] ?? 0, 1) }}%</h4>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <p class="text-muted fw-medium">One-Time Buyers</p>
                    <h4 class="mb-0">{{ number_format($cohorts['one_time_customers'] ?? 0) }}</h4>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Purchase Frequency Distribution</h4>
                    <div id="freq-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Customer Split</h4>
                    <div id="split-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $freqLabels = collect($cohorts['frequency_distribution'] ?? [])->keys()->toArray();
        $freqValues = collect($cohorts['frequency_distribution'] ?? [])->values()->toArray();
    @endphp
    new ApexCharts(document.querySelector("#freq-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [{ name: 'Customers', data: @json(count($freqValues) ? $freqValues : [0]) }],
        xaxis: { categories: @json(count($freqLabels) ? $freqLabels : ['No Data']), title: { text: 'Purchase Count' } },
        colors: ['#556ee6']
    }).render();

    new ApexCharts(document.querySelector("#split-chart"), {
        chart: { type: 'donut', height: 350 },
        series: [{{ $cohorts['repeat_customers'] ?? 0 }}, {{ $cohorts['one_time_customers'] ?? 0 }}],
        labels: ['Repeat Buyers', 'One-Time Buyers'],
        colors: ['#34c38f', '#f1b44c'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection