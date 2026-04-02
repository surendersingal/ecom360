@extends('layouts.tenant')

@section('title', 'Dashboard')

@section('content')
@php
    $user = auth()->user();
    $firstName = explode(' ', $user->name ?? 'there')[0];
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $conversionRate = number_format($funnel['overall_conversion_pct'] ?? 0, 1);
    $totalSessions = $sessions['total_sessions'] ?? 0;
    $totalEvents = $traffic['total_events'] ?? 0;
    $activeVisitors = $realtime['active_sessions'] ?? 0;
@endphp

{{-- ─── Greeting Header ─── --}}
<div class="e360-greeting">
    <div class="greeting-text">{{ $greeting }}, {{ $firstName }} 👋</div>
    <div class="greeting-sub">
        <span>{{ $tenant->name }}</span>
        <span class="e360-live-badge">
            <span class="live-dot"></span> Live
        </span>
        <span style="color:var(--neutral-400)">{{ now()->format('l, j F Y') }}</span>
    </div>
</div>

{{-- ─── KPI Cards ─── --}}
<div class="row g-3 mb-4">
    {{-- Live Visitors --}}
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-header">
                <span class="kpi-label">Live Visitors</span>
                <span class="kpi-icon visitors"><i class="bx bx-user"></i></span>
            </div>
            <div class="kpi-value" data-countup="{{ $activeVisitors }}">{{ number_format($activeVisitors) }}</div>
            <div class="kpi-trend up">
                <svg class="trend-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17l5-5 5 5"/><path d="M7 7h10"/></svg>
                Active now
            </div>
            <div class="kpi-sub">Real-time active sessions</div>
        </div>
    </div>

    {{-- Sessions (7d) --}}
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-header">
                <span class="kpi-label">Sessions (7d)</span>
                <span class="kpi-icon orders"><i class="bx bx-time-five"></i></span>
            </div>
            <div class="kpi-value" data-countup="{{ $totalSessions }}">{{ number_format($totalSessions) }}</div>
            <div class="kpi-trend up">
                <svg class="trend-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17l5-5 5 5"/><path d="M7 7h10"/></svg>
                Last 7 days
            </div>
            <div class="kpi-sparkline" id="sessions-sparkline"></div>
        </div>
    </div>

    {{-- Total Events (7d) --}}
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-header">
                <span class="kpi-label">Events (7d)</span>
                <span class="kpi-icon revenue"><i class="bx bx-file"></i></span>
            </div>
            <div class="kpi-value" data-countup="{{ $totalEvents }}">{{ number_format($totalEvents) }}</div>
            <div class="kpi-trend up">
                <svg class="trend-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17l5-5 5 5"/><path d="M7 7h10"/></svg>
                Tracked events
            </div>
        </div>
    </div>

    {{-- Conversion Rate --}}
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <div class="kpi-header">
                <span class="kpi-label">Conversion Rate</span>
                <span class="kpi-icon conversion"><i class="bx bx-target-lock"></i></span>
            </div>
            <div class="kpi-value">{{ $conversionRate }}%</div>
            <div class="kpi-trend {{ (float)$conversionRate > 3 ? 'up' : 'down' }}">
                <svg class="trend-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17l5-5 5 5"/><path d="M7 7h10"/></svg>
                Funnel completion
            </div>
        </div>
    </div>
</div>

{{-- ─── Charts Row ─── --}}
<div class="row g-3 mb-4">
    {{-- Traffic Overview Chart --}}
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Traffic Overview</h5>
                    <div class="e360-period-toggle">
                        <button class="period-btn active" data-period="7d">7D</button>
                        <button class="period-btn" data-period="30d">30D</button>
                        <button class="period-btn" data-period="90d">90D</button>
                    </div>
                </div>
                <div id="traffic-chart" style="height: 320px;"></div>
            </div>
        </div>
    </div>

    {{-- Real-Time Pulse --}}
    <div class="col-xl-4">
        <div class="card" style="height:100%;">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Real-Time Pulse</h5>
                    <span class="e360-live-badge"><span class="live-dot"></span> Live</span>
                </div>
                <div class="text-center flex-grow-1 d-flex flex-column justify-content-center">
                    <div class="e360-realtime-number" id="realtime-count">{{ $activeVisitors }}</div>
                    <div style="color:var(--neutral-500);font-size:14px;margin-top:4px;">Active Visitors</div>
                </div>
                <div style="margin-top:auto;padding-top:16px;border-top:1px solid var(--border);">
                    <div class="d-flex justify-content-between text-center">
                        <div>
                            <div style="font-family:'JetBrains Mono';font-weight:600;font-size:16px;color:var(--neutral-800)">{{ $realtime['events_per_minute'] ?? 0 }}</div>
                            <div style="font-size:11px;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.06em">Events/min</div>
                        </div>
                        <div>
                            <div style="font-family:'JetBrains Mono';font-weight:600;font-size:16px;color:var(--neutral-800)">{{ number_format($sessions['bounce_rate'] ?? 0, 1) }}%</div>
                            <div style="font-size:11px;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.06em">Bounce Rate</div>
                        </div>
                        <div>
                            <div style="font-family:'JetBrains Mono';font-weight:600;font-size:16px;color:var(--neutral-800)">{{ count($realtime['top_pages'] ?? []) }}</div>
                            <div style="font-size:11px;color:var(--neutral-400);text-transform:uppercase;letter-spacing:0.06em">Active Pages</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ─── Products & Module Health ─── --}}
<div class="row g-3 mb-4">
    {{-- Top Products --}}
    <div class="col-xl-7">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Top Products <span style="font-weight:400;color:var(--neutral-400);font-size:13px;">by purchases this week</span></h5>
                @if(count($topProducts) > 0)
                <table class="e360-table">
                    <thead>
                        <tr>
                            <th style="width:30px">#</th>
                            <th>Product</th>
                            <th class="text-end">Count</th>
                            <th class="text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topProducts as $i => $p)
                        <tr>
                            <td style="color:var(--neutral-400);font-weight:600;">{{ $i + 1 }}</td>
                            <td style="font-weight:500;">{{ $p['product_name'] ?? $p['product_id'] ?? 'Unknown' }}</td>
                            <td class="text-end"><span class="mono">{{ number_format($p['count'] ?? 0) }}</span></td>
                            <td class="text-end"><span class="mono" style="font-weight:600;color:var(--success)">${{ number_format($p['revenue'] ?? 0, 2) }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                <div class="e360-empty-state">
                    <div class="empty-icon">📦</div>
                    <h3>No product data yet</h3>
                    <p>Product analytics will appear once your store starts tracking purchases.</p>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Module Health --}}
    <div class="col-xl-5">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Module Health</h5>
                @php
                $modules = [
                    ['name' => 'DataSync',  'icon' => 'bx-transfer',      'class' => 'icon-datasync',  'status' => 'Active'],
                    ['name' => 'Analytics', 'icon' => 'bx-line-chart',    'class' => 'icon-analytics', 'status' => 'Active'],
                    ['name' => 'Marketing', 'icon' => 'bx-send',          'class' => 'icon-marketing', 'status' => 'Active'],
                    ['name' => 'Chatbot',   'icon' => 'bx-bot',           'class' => 'icon-chatbot',   'status' => 'Active'],
                    ['name' => 'AI Search', 'icon' => 'bx-search-alt-2',  'class' => 'icon-search',    'status' => 'Active'],
                    ['name' => 'Business Intel', 'icon' => 'bx-bar-chart-alt-2', 'class' => 'icon-bi', 'status' => 'Active'],
                ];
                @endphp
                @foreach($modules as $mod)
                <div class="module-health-card">
                    <div class="module-icon {{ $mod['class'] }}"><i class="bx {{ $mod['icon'] }}"></i></div>
                    <div class="module-name">{{ $mod['name'] }}</div>
                    <span class="e360-badge e360-badge-active">
                        <span class="e360-badge-live"></span>
                        {{ $mod['status'] }}
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- ─── Revenue by Source & Conversion Funnel ─── --}}
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Revenue by Source</h5>
                <div id="revenue-channel-chart" style="height: 280px;"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Conversion Funnel</h5>
                @php
                    $stages = $funnel['stages'] ?? [];
                @endphp
                @if(count($stages) > 0)
                <div class="e360-funnel">
                    @foreach($stages as $i => $stage)
                    <div class="funnel-stage">
                        <div class="stage-label">{{ $stage['stage'] ?? 'Stage' }}</div>
                        <div class="stage-value">{{ number_format($stage['unique_sessions'] ?? 0) }}</div>
                        @if($i < count($stages) - 1 && ($stage['unique_sessions'] ?? 0) > 0)
                            @php $drop = round((1 - (($stages[$i+1]['unique_sessions'] ?? 0) / $stage['unique_sessions'])) * 100); @endphp
                            <div class="stage-drop">-{{ $drop }}%</div>
                        @endif
                    </div>
                    @endforeach
                </div>
                @else
                <div class="e360-empty-state">
                    <div class="empty-icon">🔄</div>
                    <h3>No funnel data</h3>
                    <p>Conversion funnel data will appear once ecommerce events are tracked.</p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Countup animation for KPI values ──
    document.querySelectorAll('[data-countup]').forEach(function(el) {
        var target = parseInt(el.dataset.countup, 10);
        if (target > 0 && window.ecom360CountUp) {
            window.ecom360CountUp(el, target, { duration: 1200 });
        }
    });

    // ── Traffic Overview Chart ──
    var trafficChart = new ApexCharts(document.querySelector("#traffic-chart"), {
        chart: { type: 'area', height: 320 },
        series: [{ name: 'Sessions', data: @json($dailySessions['sessions'] ?? []) }],
        xaxis: { categories: @json($dailySessions['dates'] ?? []) },
        colors: ['#1A56DB'],
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05, stops: [0, 90, 100] }
        },
        stroke: { curve: 'smooth', width: 2.5 },
        dataLabels: { enabled: false },
        grid: { borderColor: '#E2E8F0', strokeDashArray: 4 }
    });
    trafficChart.render();

    // ── Revenue by Source ──
    @php
        $revLabels = collect($revBySource)->pluck('source')->toArray();
        $revValues = collect($revBySource)->pluck('revenue')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#revenue-channel-chart"), {
        chart: { type: 'donut', height: 280 },
        series: @json(count($revValues) ? $revValues : [1]),
        labels: @json(count($revLabels) ? $revLabels : ['No Data Yet']),
        colors: count(@json($revLabels)) > 0
            ? ['#1A56DB','#10B981','#F59E0B','#7C3AED','#0891B2']
            : ['#E2E8F0'],
        legend: { position: 'bottom', fontSize: '13px', fontFamily: "'Inter', sans-serif" },
        plotOptions: { pie: { donut: { size: '65%' } } },
        dataLabels: { enabled: false }
    }).render();

    // ── Sessions sparkline ──
    var sparkData = @json($dailySessions['sessions'] ?? []);
    if (sparkData.length > 0 && document.querySelector("#sessions-sparkline")) {
        new ApexCharts(document.querySelector("#sessions-sparkline"), {
            chart: { type: 'area', height: 40, sparkline: { enabled: true } },
            series: [{ data: sparkData }],
            colors: ['#3B82F6'],
            fill: {
                type: 'gradient',
                gradient: { opacityFrom: 0.4, opacityTo: 0.05 }
            },
            stroke: { width: 2, curve: 'smooth' },
            tooltip: { enabled: false }
        }).render();
    }

    // ── Period toggle ──
    document.querySelectorAll('.period-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            // TODO: reload chart data for the selected period via AJAX
        });
    });
});
</script>
@endsection
