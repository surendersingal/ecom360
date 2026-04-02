@extends('layouts.tenant')
@section('title', 'Visitors Overview')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-user" style="color:var(--analytics);"></i> Visitors Overview</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                    <li class="breadcrumb-item active">Visitors</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        {{-- KPI Row --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Visits</span><span class="kpi-icon visitors"><i class="bx bx-user"></i></span></div>
                <div class="kpi-value" id="v-visits">—</div><div class="kpi-sub" id="v-visits-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Visitors</span><span class="kpi-icon orders"><i class="bx bx-body"></i></span></div>
                <div class="kpi-value" id="v-unique">—</div><div class="kpi-sub" id="v-unique-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. Session Duration</span><span class="kpi-icon"><i class="bx bx-time-five" style="color:var(--chatbot);"></i></span></div>
                <div class="kpi-value" id="v-duration">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Actions per Visit</span><span class="kpi-icon conversion"><i class="bx bx-pointer"></i></span></div>
                <div class="kpi-value" id="v-actions">—</div></div>
            </div>
        </div>

        {{-- Visits Over Time --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Visits Over Time</h5>
                        <div id="visits-trend-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- New vs Returning + Visitor Frequency --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">New vs Returning Visitors</h5>
                        <div id="new-returning-chart" style="height:280px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Visit Frequency</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Number of Visits</th><th class="text-end">Visitors</th><th class="text-end">% of Total</th></tr></thead>
                                <tbody id="frequency-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cohort Retention --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Visitor Retention Cohorts</h5>
                        <div class="table-responsive" id="cohort-table-wrap" style="max-height:400px;overflow:auto;">
                            <div class="text-center text-muted py-4"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection

@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    const range = new URLSearchParams(location.search).get('date_range') || '30d';

    $.when(
        $.get(API + '/sessions', { date_range: range }),
        $.get(API + '/cohorts', { date_range: range }).catch(()=>[{}]),
        $.get(API + '/visitor-frequency', { date_range: range }).catch(()=>[{}])
    ).then(function(sessRes, cohortsRes, freqRes) {
        const s = sessRes[0]?.data || sessRes[0] || {};
        const c = cohortsRes[0]?.data || cohortsRes[0] || {};
        const freqData = freqRes[0]?.data || freqRes[0] || {};

        // KPIs — sessions API returns { metrics: { total_sessions, bounce_rate, avg_session_duration_seconds } }
        const metrics = s.metrics || {};
        $('#v-visits').text(EcomUtils.number(metrics.total_sessions || 0));
        $('#v-unique').text(EcomUtils.number(freqData.total_visitors || metrics.total_sessions || 0));
        const dur = metrics.avg_session_duration_seconds || 0;
        $('#v-duration').text(dur > 60 ? Math.floor(dur/60)+'m '+Math.round(dur%60)+'s' : Math.round(dur)+'s');
        const actions = metrics.avg_events_per_session || (s.total_events && metrics.total_sessions ? (s.total_events/metrics.total_sessions).toFixed(1) : '—');
        $('#v-actions').text(typeof actions === 'number' ? actions.toFixed(1) : actions);

        // Visits trend — daily_trend is { dates: [...], sessions: [...] } (parallel arrays)
        const trend = s.daily_trend || {};
        const dates = trend.dates || [];
        const sessArr = trend.sessions || [];
        if (dates.length) {
            new ApexCharts(document.querySelector('#visits-trend-chart'), {
                chart: { type:'area', height:340, fontFamily:'Inter, sans-serif', toolbar:{show:false} },
                series: [
                    { name:'Visits', data: sessArr },
                ],
                xaxis: { categories: dates, tickAmount: Math.min(dates.length,15) },
                yaxis: { labels: { formatter: v=>EcomUtils.number(Math.round(v)) } },
                colors: ['#1A56DB','#10B981'],
                fill: { type:'gradient', gradient:{opacityFrom:0.3,opacityTo:0.05} },
                stroke: { curve:'smooth', width:2 },
                dataLabels: { enabled:false },
                grid: { borderColor:'#E2E8F0', strokeDashArray:4 },
            }).render();
        }

        // New vs Returning — API: { new_sessions, returning_sessions, new_pct, returning_pct }
        const nvr = s.new_vs_returning || {};
        const newV = nvr.new_sessions || nvr.new || nvr.new_visitors || 0;
        const retV = nvr.returning_sessions || nvr.returning || nvr.returning_visitors || 0;
        if (newV || retV) {
            new ApexCharts(document.querySelector('#new-returning-chart'), {
                chart: { type:'donut', height:280, fontFamily:'Inter' },
                series: [newV, retV],
                labels: ['New Visitors','Returning Visitors'],
                colors: ['#1A56DB','#10B981'],
                legend: { position:'bottom' },
                plotOptions: { pie: { donut: { size:'65%', labels: { show:true, total: { show:true, label:'Total', formatter:w=>EcomUtils.number(newV+retV) } } } } },
            }).render();
        }

        // Visit frequency from real backend data
        const freq = freqData.frequency || [];
        const totalVisitors = freqData.total_visitors || freq.reduce((s,f) => s + (f.count||0), 0) || 1;
        let fhtml = '';
        freq.forEach(f => {
            fhtml += `<tr><td style="font-size:13px;">${f.name}</td><td class="text-end mono" style="font-size:13px;">${EcomUtils.number(f.count||0)}</td><td class="text-end mono" style="font-size:13px;">${f.percentage != null ? f.percentage.toFixed(1) : (f.count/totalVisitors*100).toFixed(1)}%</td></tr>`;
        });
        if (!fhtml) fhtml = '<tr><td colspan="3" class="text-muted text-center py-3">No frequency data</td></tr>';
        $('#frequency-body').html(fhtml);

        // Cohorts — API: { retention: { months, retention_matrix }, repeat_purchase, clv_by_segment }
        const retention = c.retention || {};
        const cohorts = retention.retention_matrix || c.retention_cohorts || c.cohorts || [];
        if (cohorts.length) {
            let cht = '<table class="table table-sm table-bordered mb-0" style="font-size:11px;"><thead><tr><th>Cohort</th><th>Users</th>';
            const maxWeeks = Math.min(cohorts.reduce((m,c)=>Math.max(m,(c.periods||c.weeks||[]).length),0), 12);
            for(let i=0;i<maxWeeks;i++) cht += `<th class="text-center">W${i+1}</th>`;
            cht += '</tr></thead><tbody>';
            cohorts.slice(0,12).forEach(co => {
                cht += `<tr><td style="white-space:nowrap;">${co.cohort_month||co.cohort||co.month||co.period}</td><td class="mono">${co.cohort_size||co.total||co.users||0}</td>`;
                const periods = co.periods||co.weeks||co.retention||[];
                for(let i=0;i<maxWeeks;i++) {
                    const val = periods[i] !== undefined ? periods[i] : '-';
                    const pct = typeof val === 'number' ? val : 0;
                    const bg = pct > 50 ? 'rgba(16,185,129,0.15)' : pct > 20 ? 'rgba(26,86,219,0.1)' : pct > 0 ? 'rgba(245,158,11,0.1)' : '';
                    cht += `<td class="text-center mono" style="background:${bg}">${typeof val === 'number' ? val.toFixed(0)+'%' : val}</td>`;
                }
                cht += '</tr>';
            });
            cht += '</tbody></table>';
            $('#cohort-table-wrap').html(cht);
        } else {
            $('#cohort-table-wrap').html('<div class="text-muted text-center py-3">No cohort data available</div>');
        }
    });
})();
</script>
@endsection
