@extends('layouts.tenant')

@section('title', 'Webhooks')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Webhooks</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Webhooks</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Webhooks Overview</h4>
                    <div id="main-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Distribution</h4>
                    <div id="dist-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Details</h4>
                    <p class="text-muted">Configure webhooks to send event data to external services.</p>
                    <div class="text-center py-4">
                        <i class="bx bx-link-external text-muted" style="font-size: 48px;"></i>
                        <p class="text-muted mt-3">Integrate the Ecom360 SDK to start seeing data here.</p>
                        <a href="{{ route('tenant.integration', $tenant->slug) }}" class="btn btn-primary btn-sm">
                            <i class="bx bx-code-alt me-1"></i> View Integration Guide
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    new ApexCharts(document.querySelector("#main-chart"), {
        chart: { type: 'area', height: 350, toolbar: { show: false } },
        series: [{ name: 'Webhooks', data: [0,0,0,0,0,0,0] }],
        xaxis: { categories: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] },
        colors: ['#556ee6'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false }
    }).render();

    new ApexCharts(document.querySelector("#dist-chart"), {
        chart: { type: 'donut', height: 350 },
        series: [1],
        labels: ['No Data'],
        colors: ['#e0e0e0'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection