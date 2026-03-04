@extends('layouts.tenant')

@section('title', 'Campaign Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Campaign Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Campaigns</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Channel Attribution (Revenue)</h4>
                    <div class="mb-2"><strong>Total Revenue:</strong> ${{ number_format($channelAttrib['total_revenue'] ?? 0, 2) }}</div>
                    <div id="channel-chart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Top Referrer Sources</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead class="table-light"><tr><th>Referrer</th><th class="text-end">Sessions</th></tr></thead>
                            <tbody>
                                @forelse($referrers as $r)
                                <tr><td>{{ $r['referrer'] ?? '-' }}</td><td class="text-end">{{ number_format($r['sessions'] ?? 0) }}</td></tr>
                                @empty
                                <tr><td colspan="2" class="text-center text-muted">No referrer data</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Campaign Performance</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-nowrap mb-0">
                            <thead class="table-light"><tr><th>Campaign</th><th>Source</th><th>Medium</th><th class="text-end">Sessions</th></tr></thead>
                            <tbody>
                                @forelse($campaignPerf as $c)
                                <tr><td>{{ $c['campaign'] ?? '-' }}</td><td>{{ $c['source'] ?? '-' }}</td><td>{{ $c['medium'] ?? '-' }}</td><td class="text-end">{{ number_format($c['sessions'] ?? 0) }}</td></tr>
                                @empty
                                <tr><td colspan="4" class="text-center text-muted">No campaign data. Add UTM parameters to your URLs.</td></tr>
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
    @php
        $chLabels = collect($channelAttrib['channels'] ?? [])->pluck('channel')->toArray();
        $chValues = collect($channelAttrib['channels'] ?? [])->pluck('revenue')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#channel-chart"), {
        chart: { type: 'donut', height: 300 },
        series: @json(count($chValues) ? $chValues : [0]),
        labels: @json(count($chLabels) ? $chLabels : ['No Data']),
        colors: ['#556ee6','#34c38f','#f1b44c','#f46a6a','#50a5f1'],
        legend: { position: 'bottom' }
    }).render();
</script>
@endsection