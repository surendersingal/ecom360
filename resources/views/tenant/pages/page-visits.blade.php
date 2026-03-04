@extends('layouts.tenant')

@section('title', 'Page Visits')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Page Visits</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Page Visits</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Daily Session Trend</h4>
                    <div id="trend-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Top Landing Pages</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead class="table-light"><tr><th>URL</th><th class="text-end">Sessions</th></tr></thead>
                            <tbody>
                                @forelse($landing as $p)
                                <tr><td class="text-truncate" style="max-width:300px;">{{ $p['url'] ?? '-' }}</td><td class="text-end">{{ number_format($p['sessions'] ?? 0) }}</td></tr>
                                @empty
                                <tr><td colspan="2" class="text-center text-muted">No data yet</td></tr>
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
                    <h4 class="card-title mb-4">Top Exit Pages</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead class="table-light"><tr><th>URL</th><th class="text-end">Sessions</th></tr></thead>
                            <tbody>
                                @forelse($exit as $p)
                                <tr><td class="text-truncate" style="max-width:300px;">{{ $p['url'] ?? '-' }}</td><td class="text-end">{{ number_format($p['sessions'] ?? 0) }}</td></tr>
                                @empty
                                <tr><td colspan="2" class="text-center text-muted">No data yet</td></tr>
                                @endforelse
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
    new ApexCharts(document.querySelector("#trend-chart"), {
        chart: { type: 'area', height: 300, toolbar: { show: false } },
        series: [{ name: 'Sessions', data: @json($trend['sessions'] ?? []) }],
        xaxis: { categories: @json($trend['dates'] ?? []) },
        colors: ['#556ee6'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false }
    }).render();
</script>
@endsection