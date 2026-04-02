@extends('layouts.tenant')
@section('title', 'Entry Pages')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-log-in-circle" style="color:var(--analytics);"></i> Entry Pages</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Entry Pages</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Entries</span></div><div class="kpi-value" id="ep-entries">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Landing Pages</span></div><div class="kpi-value" id="ep-unique">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. Bounce Rate</span></div><div class="kpi-value" id="ep-bounce">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Best Performer</span></div><div class="kpi-value" id="ep-best" style="font-size:14px;">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Entry Pages</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Landing Page</th><th class="text-end">Entries</th><th class="text-end">% of Total</th></tr></thead>
                            <tbody id="entry-body"><tr><td colspan="4" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Entry Pages</h5>
                    <div id="entry-chart" style="height:320px;"></div>
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

    $.get(API + '/sessions', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const landing = d.top_landing_pages || d.landing_pages || d.entry_pages || [];
        const total = landing.reduce((s,p) => s + (p.sessions || p.count || p.entries || p.visits || 0), 0);

        $('#ep-entries').text(EcomUtils.number(total));
        $('#ep-unique').text(landing.length);

        if (landing.length) {
            const avgBounce = landing.reduce((s,p)=>s+(p.bounce_rate||0),0)/landing.length;
            $('#ep-bounce').text(avgBounce.toFixed(1)+'%');
            $('#ep-best').text((landing[0].url || landing[0].page || '/').replace(/https?:\/\/[^/]+/,''));

            let h = '';
            landing.forEach((p, i) => {
                const url = p.url || p.page || p.path || '/';
                const entries = p.sessions || p.count || p.entries || p.visits || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${url}">${url.replace(/https?:\/\/[^/]+/,'')}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(entries)}</td>
                    <td class="text-end mono" style="font-size:12px;">${total ? (entries/total*100).toFixed(1)+'%' : '—'}</td>
                </tr>`;
            });
            $('#entry-body').html(h);

            const top = landing.slice(0,6);
            new ApexCharts(document.querySelector('#entry-chart'), {
                chart:{type:'bar',height:320,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Entries',data:top.map(p=>p.sessions||p.count||p.entries||0)}],
                xaxis:{categories:top.map(p=>(p.url||p.page||'/').replace(/https?:\/\/[^/]+/,'').substring(0,25))},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'60%'}},
                colors:['#1A56DB'],
                dataLabels:{enabled:false},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load entry pages data');
        $('#entry-body').html('<tr><td colspan="4" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
