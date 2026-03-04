@extends('layouts.tenant')

@section('title', 'Cohort Analysis')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Cohort Analysis</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Cohorts</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- Repeat Purchase Stats -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Total Customers</p>
                        <h4 class="mb-0">{{ number_format($repeat['total_customers'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Repeat Purchase Rate</p>
                        <h4 class="mb-0">{{ number_format($repeat['repeat_purchase_rate'] ?? 0, 1) }}%</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">Repeat Customers</p>
                        <h4 class="mb-0">{{ number_format($repeat['repeat_customers'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex"><div class="flex-grow-1">
                        <p class="text-muted fw-medium">One-Time Buyers</p>
                        <h4 class="mb-0">{{ number_format($repeat['one_time_customers'] ?? 0) }}</h4>
                    </div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- CLV by Segment -->
    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">CLV by Customer Segment</h4>
                    <div id="clv-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Customer Segments</h4>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light"><tr><th>Segment</th><th class="text-end">Count</th><th class="text-end">Avg CLV</th><th class="text-end">Total Value</th></tr></thead>
                            <tbody>
                                @forelse($clv as $s)
                                <tr><td>{{ $s['segment'] ?? '-' }}</td><td class="text-end">{{ number_format($s['count'] ?? 0) }}</td><td class="text-end">${{ number_format($s['avg_clv'] ?? 0, 2) }}</td><td class="text-end">${{ number_format($s['total_value'] ?? 0, 2) }}</td></tr>
                                @empty
                                <tr><td colspan="4" class="text-center text-muted">No customer data yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Retention Matrix -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Monthly Retention Cohorts</h4>
                    @if(count($retention['retention_matrix'] ?? []) > 0)
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Cohort</th>
                                    <th>Size</th>
                                    @foreach($retention['months'] ?? [] as $i => $m)
                                    <th class="text-center">Month {{ $i }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($retention['retention_matrix'] as $row)
                                <tr>
                                    <td>{{ $row['cohort_month'] }}</td>
                                    <td>{{ $row['cohort_size'] }}</td>
                                    @foreach($row['retention'] ?? [] as $pct)
                                    <td class="text-center" style="background-color: rgba(85,110,230,{{ max(0.05, $pct/100) }});">{{ number_format($pct, 1) }}%</td>
                                    @endforeach
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <p class="text-center text-muted py-4">No retention data available yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $clvLabels = collect($clv)->pluck('segment')->toArray();
        $clvValues = collect($clv)->pluck('avg_clv')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#clv-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [{ name: 'Avg CLV', data: @json(count($clvValues) ? $clvValues : [0]) }],
        xaxis: { categories: @json(count($clvLabels) ? $clvLabels : ['No Data']) },
        colors: ['#556ee6'],
        dataLabels: { enabled: true, formatter: function(v) { return '$' + v.toFixed(0); } }
    }).render();
</script>
@endsection