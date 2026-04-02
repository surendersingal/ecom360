@extends('layouts.tenant')
@section('title', 'Campaigns')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-target-lock" style="color:var(--analytics);"></i> Campaigns</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Campaigns</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Active Campaigns</span></div><div class="kpi-value" id="cm-active">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Campaign Visits</span></div><div class="kpi-value" id="cm-visits">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Campaign Conversions</span></div><div class="kpi-value" id="cm-conv">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Campaign Revenue</span></div><div class="kpi-value" id="cm-rev">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Campaign Performance (UTM)</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-primary active" data-dim="campaign">Campaign</button>
                            <button class="btn btn-outline-primary" data-dim="source">Source</button>
                            <button class="btn btn-outline-primary" data-dim="medium">Medium</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Name</th><th class="text-end">Visits</th><th class="text-end">Users</th><th class="text-end">Bounce Rate</th><th class="text-end">Conversions</th><th class="text-end">Revenue</th><th class="text-end">ROAS</th></tr></thead>
                            <tbody id="cm-body"><tr><td colspan="7" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Campaign Revenue Comparison</h5>
                    <div id="cm-rev-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Conversion Rate by Campaign</h5>
                    <div id="cm-conv-chart" style="height:300px;"></div>
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
    let campaignData = {};

    $.get(API + '/campaigns', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        campaignData = d;

        // API: { utm_breakdown: { campaigns: [{name,count}], sources: [{name,count}], mediums: [{name,count}] },
        //        channel_attribution: { channels: [{channel, revenue, conversions}] },
        //        referrer_sources: [{referrer, sessions}] }
        const utmBreakdown = d.utm_breakdown || {};
        const campaigns = utmBreakdown.campaigns || d.campaigns || [];
        const sources = utmBreakdown.sources || d.sources || [];
        const mediums = utmBreakdown.mediums || d.mediums || [];
        const channelAttr = d.channel_attribution || {};
        const channels = channelAttr.channels || [];

        const totalVisits = campaigns.reduce((s,c) => s + (c.count || c.visits || 0), 0);
        const totalConv = channels.reduce((s,c) => s + (c.conversions || 0), 0);
        const totalRev = channelAttr.total_revenue || channels.reduce((s,c) => s + (c.revenue || 0), 0);

        $('#cm-active').text(campaigns.length);
        $('#cm-visits').text(EcomUtils.number(totalVisits));
        $('#cm-conv').text(EcomUtils.number(totalConv));
        $('#cm-rev').text('₹' + EcomUtils.number(totalRev));

        renderDim(campaigns, 'campaign');

        // Toggler
        $('[data-dim]').on('click', function() {
            $('[data-dim]').removeClass('active');
            $(this).addClass('active');
            const dim = $(this).data('dim');
            if (dim === 'source') renderDim(sources, 'source');
            else if (dim === 'medium') renderDim(mediums, 'medium');
            else renderDim(campaigns, 'campaign');
        });

        // Revenue bar chart
        if (campaigns.length) {
            const top = campaigns.slice(0, 8);
            new ApexCharts(document.querySelector('#cm-rev-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:top.map(c=>c.count||c.visits||0)}],
                xaxis:{categories:top.map(c=>(c.name||c.campaign||'?').substring(0,20))},
                plotOptions:{bar:{borderRadius:4,columnWidth:'60%'}},
                colors:['#1A56DB'],
                dataLabels:{enabled:false},
                legend:{position:'top'},
            }).render();

            // Conversion rate chart — use channel attribution if available
            if (channels.length) {
                new ApexCharts(document.querySelector('#cm-conv-chart'), {
                    chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                    series:[{name:'Revenue',data:channels.slice(0,8).map(c=>c.revenue||0)}],
                    xaxis:{categories:channels.slice(0,8).map(c=>(c.channel||'?').substring(0,20))},
                    plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                    colors:['#7C3AED'],
                    dataLabels:{enabled:true,formatter:v=>'$'+EcomUtils.number(v)},
                }).render();
            }
        }
    }).catch(function(){
        console.warn('Failed to load campaigns data');
        $('#cm-body').html('<tr><td colspan="7" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });

    function renderDim(items, dimKey) {
        if (!items || !items.length) { $('#cm-body').html('<tr><td colspan="7" class="text-center text-muted py-3">No data</td></tr>'); return; }
        let h = '';
        items.forEach(c => {
            const v = c.count || c.visits || c.sessions || 0;
            const conv = c.conversions || 0;
            const rev = c.revenue || 0;
            h += `<tr>
                <td style="font-size:12px;font-weight:500;">${c.name||c[dimKey]||c.campaign||c.source||c.medium||c.referrer||'—'}</td>
                <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(v)}</td>
                <td class="text-end mono" style="font-size:12px;">—</td>
                <td class="text-end mono" style="font-size:12px;">—</td>
                <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(conv)}</td>
                <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(rev)}</td>
                <td class="text-end mono" style="font-size:12px;">—</td>
            </tr>`;
        });
        $('#cm-body').html(h);
    }
})();
</script>
@endsection
