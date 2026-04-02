@extends('layouts.tenant')

@section('title', 'Real-Time Traffic')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Real-Time Pulse</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Real-Time</li>
                </ol>
            </nav>
        </div>
        <div class="header-actions">
            <span class="e360-live-badge" style="font-size:13px;"><span class="live-dot"></span> Live Data</span>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Active Sessions</span>
                    <span class="kpi-icon visitors"><i class="bx bx-user"></i></span>
                </div>
                <div class="kpi-value">{{ $rt['active_sessions'] ?? 0 }}</div>
                <div class="kpi-trend up">
                    <span class="e360-live-badge" style="font-size:11px;padding:2px 8px;"><span class="live-dot"></span> Live</span>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Events / Minute</span>
                    <span class="kpi-icon orders"><i class="bx bx-pulse"></i></span>
                </div>
                <div class="kpi-value">{{ $rt['events_per_minute'] ?? 0 }}</div>
                <div class="kpi-sub">Throughput rate</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Active Pages</span>
                    <span class="kpi-icon revenue"><i class="bx bx-file"></i></span>
                </div>
                <div class="kpi-value">{{ count($rt['top_pages'] ?? []) }}</div>
                <div class="kpi-sub">Unique page views</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Countries</span>
                    <span class="kpi-icon conversion"><i class="bx bx-globe"></i></span>
                </div>
                <div class="kpi-value">{{ count($rt['geo_breakdown'] ?? []) }}</div>
                <div class="kpi-sub">Geographic reach</div>
            </div>
        </div>
    </div>

    {{-- Content Row --}}
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card" style="height:100%;">
                <div class="card-body">
                    <h5 class="card-title">Active Pages</h5>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <thead>
                                <tr>
                                    <th>Page URL</th>
                                    <th class="text-end">Active Users</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rt['top_pages'] ?? [] as $page)
                                <tr>
                                    <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;">{{ $page['url'] ?? $page['page'] ?? '-' }}</td>
                                    <td class="text-end">
                                        <span class="mono" style="font-weight:600;color:var(--primary-500);">{{ $page['count'] ?? $page['active'] ?? 0 }}</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="2">
                                        <div class="e360-empty-state" style="padding:24px 0;">
                                            <div class="empty-icon">🌐</div>
                                            <h3>No active pages</h3>
                                            <p>Pages will appear here when visitors are browsing your store.</p>
                                        </div>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card" style="height:100%;">
                <div class="card-body">
                    <h5 class="card-title">Geographic Breakdown</h5>
                    <div id="geo-chart" style="height: 320px;"></div>
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
        chart: { type: 'bar', height: 320, toolbar: { show: false } },
        series: [{ name: 'Visitors', data: @json(count($geoValues) ? $geoValues : [0]) }],
        xaxis: { categories: @json(count($geoLabels) ? $geoLabels : ['No Data']) },
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
        colors: ['var(--primary-500, #1A56DB)'],
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.9, opacityTo: 0.6, stops: [0, 100] }
        },
        dataLabels: { enabled: true, style: { fontSize: '12px', fontFamily: "'JetBrains Mono', monospace", fontWeight: 600 } },
        grid: { borderColor: '#E2E8F0', strokeDashArray: 4 }
    }).render();
</script>
@endsection
@endsection