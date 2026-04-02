@extends('layouts.tenant')
@section('title', 'Referrers')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-link-external" style="color:var(--analytics);"></i> Referrers</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Referrers</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Referring Sites</span></div><div class="kpi-value" id="rf-sites">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Referral Visits</span></div><div class="kpi-value" id="rf-visits">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Top Referrer</span></div><div class="kpi-value" id="rf-top" style="font-size:14px;">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Social Visits</span></div><div class="kpi-value" id="rf-social">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-5">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Referrer Type Distribution</h5>
                    <div id="rf-type-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-7">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Referring Websites</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Website</th><th class="text-end">Visits</th><th class="text-end">% Share</th></tr></thead>
                            <tbody id="rf-body"><tr><td colspan="4" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Social Networks</h5>
                    <div id="rf-social-chart" style="height:280px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Search Engines</h5>
                    <div id="rf-search-chart" style="height:280px;"></div>
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

    $.get(API + '/campaigns', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const referrers = d.referrer_sources || d.referrers || d.websites || [];
        const social = referrers.filter(r => (r.type||r.category||'').toLowerCase() === 'social' || ['facebook','twitter','instagram','pinterest','linkedin','youtube','tiktok','reddit'].includes((r.referrer||r.name||r.domain||'').toLowerCase()));
        const search = referrers.filter(r => (r.type||r.category||'').toLowerCase() === 'search' || ['google','bing','yahoo','duckduckgo','baidu'].includes((r.referrer||r.name||r.domain||'').toLowerCase()));
        const total = referrers.reduce((s,r) => s + (r.sessions || r.visits || r.count || 0), 0);

        $('#rf-sites').text(referrers.length);
        $('#rf-visits').text(EcomUtils.number(total));
        if (referrers.length) $('#rf-top').text(referrers[0].referrer || referrers[0].name || referrers[0].domain || '—');
        $('#rf-social').text(EcomUtils.number(social.reduce((s,r) => s + (r.sessions||r.visits||r.count||0),0)));

        // Table
        if (referrers.length) {
            let h = '';
            referrers.forEach((r, i) => {
                const v = r.sessions || r.visits || r.count || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;font-weight:500;">${r.referrer||r.name||r.domain||'—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(v)}</td>
                    <td class="text-end mono" style="font-size:12px;">${total?(v/total*100).toFixed(1):'0'}%</td>
                </tr>`;
            });
            $('#rf-body').html(h);
        }

        // Type distribution
        const types = {};
        referrers.forEach(r => {
            const t = r.type || r.category || 'Other';
            types[t] = (types[t]||0) + (r.sessions||r.visits||r.count||0);
        });
        const typeKeys = Object.keys(types);
        if (typeKeys.length) {
            new ApexCharts(document.querySelector('#rf-type-chart'), {
                chart:{type:'donut',height:300,fontFamily:'Inter'},
                series:typeKeys.map(k=>types[k]),
                labels:typeKeys,
                colors:['#1A56DB','#059669','#E11D48','#D97706','#7C3AED','#6B7280'],
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }

        // Social chart
        if (social.length) {
            new ApexCharts(document.querySelector('#rf-social-chart'), {
                chart:{type:'bar',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:social.slice(0,6).map(r=>r.sessions||r.visits||r.count||0)}],
                xaxis:{categories:social.slice(0,6).map(r=>r.referrer||r.name||r.domain||'?')},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#E11D48'],dataLabels:{enabled:false},
            }).render();
        }

        // Search chart
        if (search.length) {
            new ApexCharts(document.querySelector('#rf-search-chart'), {
                chart:{type:'bar',height:280,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Visits',data:search.slice(0,6).map(r=>r.sessions||r.visits||r.count||0)}],
                xaxis:{categories:search.slice(0,6).map(r=>r.referrer||r.name||r.domain||'?')},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#059669'],dataLabels:{enabled:false},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load referrers data');
        $('#rf-body').html('<tr><td colspan="4" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
