@extends('layouts.tenant')

@section('title', 'Sessions Explorer')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Sessions Explorer</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Sessions</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Total Sessions</span>
                    <span class="kpi-icon orders"><i class="bx bx-time-five"></i></span>
                </div>
                <div class="kpi-value" data-countup="{{ $metrics['total_sessions'] ?? 0 }}">{{ number_format($metrics['total_sessions'] ?? 0) }}</div>
                <div class="kpi-sub">Last 7 days</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Bounce Rate</span>
                    <span class="kpi-icon conversion"><i class="bx bx-exit"></i></span>
                </div>
                <div class="kpi-value">{{ number_format($metrics['bounce_rate'] ?? 0, 1) }}%</div>
                <div class="kpi-trend {{ ($metrics['bounce_rate'] ?? 0) < 40 ? 'up' : 'down' }}">
                    {{ ($metrics['bounce_rate'] ?? 0) < 40 ? 'Good' : 'Needs attention' }}
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Pages / Session</span>
                    <span class="kpi-icon revenue"><i class="bx bx-file"></i></span>
                </div>
                <div class="kpi-value">{{ number_format($metrics['avg_pages_per_session'] ?? 0, 1) }}</div>
                <div class="kpi-sub">Avg engagement depth</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Avg Duration</span>
                    <span class="kpi-icon visitors"><i class="bx bx-timer"></i></span>
                </div>
                <div class="kpi-value">{{ $metrics['avg_session_duration_formatted'] ?? '0s' }}</div>
                <div class="kpi-sub">Time on site</div>
            </div>
        </div>
    </div>

    {{-- Charts --}}
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Daily Session Trend</h5>
                    <div id="session-trend" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card" style="height:100%;">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">New vs Returning</h5>
                    <div id="new-vs-return" style="flex:1;min-height:280px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Countup
    document.querySelectorAll('[data-countup]').forEach(function(el) {
        var target = parseInt(el.dataset.countup, 10);
        if (target > 0 && window.ecom360CountUp) window.ecom360CountUp(el, target, { duration: 1200 });
    });

    new ApexCharts(document.querySelector("#session-trend"), {
        chart: { type: 'area', height: 350, toolbar: { show: false } },
        series: [{ name: 'Sessions', data: @json($trend['sessions'] ?? []) }],
        xaxis: { categories: @json($trend['dates'] ?? []) },
        colors: ['#1A56DB'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05, stops: [0, 90, 100] } },
        stroke: { curve: 'smooth', width: 2.5 },
        dataLabels: { enabled: false },
        grid: { borderColor: '#E2E8F0', strokeDashArray: 4 }
    }).render();

    new ApexCharts(document.querySelector("#new-vs-return"), {
        chart: { type: 'donut', height: 300 },
        series: [{{ $newVsReturn['new_sessions'] ?? 0 }}, {{ $newVsReturn['returning_sessions'] ?? 0 }}],
        labels: ['New ({{ number_format($newVsReturn['new_pct'] ?? 0, 1) }}%)', 'Returning ({{ number_format($newVsReturn['returning_pct'] ?? 0, 1) }}%)'],
        colors: ['#1A56DB', '#10B981'],
        plotOptions: { pie: { donut: { size: '65%' } } },
        legend: { position: 'bottom', fontSize: '13px', fontFamily: "'Inter', sans-serif" },
        dataLabels: { enabled: false }
    }).render();
});
</script>
@endsection