@extends('layouts.tenant')
@section('title', 'Acquisition Channels')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-git-merge" style="color:var(--analytics);"></i> Channels</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Channels</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Conversions</span></div><div class="kpi-value" id="ch-visits">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Channels</span></div><div class="kpi-value" id="ch-count">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Top Channel</span></div><div class="kpi-value" id="ch-top" style="font-size:15px;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Direct Traffic %</span></div><div class="kpi-value" id="ch-direct">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Channel Distribution</h5>
                    <div id="ch-pie-chart" style="height:320px;"></div>
                </div></div>
            </div>
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Channel Performance</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Channel</th><th class="text-end">Conversions</th><th class="text-end">% Share</th><th class="text-end">Revenue</th><th class="text-end">Rev. %</th></tr></thead>
                            <tbody id="ch-body"><tr><td colspan="5" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Channel Trends Over Time</h5>
                    <div id="ch-trend-chart" style="height:300px;"></div>
                </div></div>
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
    const channelColors = {'Direct':'#1A56DB','Organic Search':'#059669','Social':'#E11D48','Referral':'#D97706','Email':'#7C3AED','Paid Search':'#0891B2','Paid Social':'#DC2626','Display':'#6B7280'};

    $.get(API + '/campaigns', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        // campaigns API: { channel_attribution: { channels: [{channel, revenue, conversions, revenue_pct}] } }
        const ca = d.channel_attribution || {};
        const channels = ca.channels || d.channels || d.traffic_sources || [];
        const total = channels.reduce((s,c) => s + (c.conversions || c.sessions || c.count || c.visits || 0), 0);

        $('#ch-visits').text(EcomUtils.number(total));
        $('#ch-count').text(channels.length);
        if (channels.length) {
            $('#ch-top').text(channels[0].channel || channels[0].name || '—');
            const direct = channels.find(c => (c.channel||c.name||'').toLowerCase() === 'direct');
            $('#ch-direct').text(direct && total ? ((direct.conversions||direct.visits||direct.count||0)/total*100).toFixed(1)+'%' : '—');

            // Table — channel data has revenue, conversions, revenue_pct
            let h = '';
            channels.forEach(c => {
                const v = c.conversions || c.sessions || c.count || c.visits || 0;
                const name = c.channel || c.name || 'Unknown';
                const color = channelColors[name] || '#6B7280';
                h += `<tr>
                    <td style="font-size:12px;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:6px;"></span>${name}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(v)}</td>
                    <td class="text-end mono" style="font-size:12px;">${total?(v/total*100).toFixed(1):'0'}%</td>
                    <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(c.revenue||0)}</td>
                    <td class="text-end mono" style="font-size:12px;">${c.revenue_pct != null ? c.revenue_pct.toFixed(1)+'%' : '—'}</td>
                </tr>`;
            });
            $('#ch-body').html(h);

            // Pie
            new ApexCharts(document.querySelector('#ch-pie-chart'), {
                chart:{type:'donut',height:320,fontFamily:'Inter'},
                series:channels.map(c=>c.revenue||c.conversions||c.count||0),
                labels:channels.map(c=>c.channel||c.name||'?'),
                colors:channels.map(c=>channelColors[c.channel||c.name]||'#6B7280'),
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }

        // Trend — use sessions daily_trend (parallel arrays) instead of fake proportional split
        $.get(API + '/sessions', { date_range: range }).then(function(tRes) {
            const t = tRes?.data || tRes || {};
            const trend = t.daily_trend || {};
            const dates = trend.dates || [];
            const sessArr = trend.sessions || [];
            if (dates.length) {
                new ApexCharts(document.querySelector('#ch-trend-chart'), {
                    chart:{type:'area',height:300,fontFamily:'Inter',toolbar:{show:false}},
                    series:[{name:'Total Sessions', data:sessArr}],
                    xaxis:{categories:dates,tickAmount:Math.min(dates.length,12)},
                    colors:['#1A56DB'],
                    fill:{type:'gradient',gradient:{opacityFrom:0.4,opacityTo:0.1}},
                    stroke:{curve:'smooth',width:1.5},
                    dataLabels:{enabled:false},
                    legend:{position:'top'},
                }).render();
            }
        });
    });
})();
</script>
@endsection
