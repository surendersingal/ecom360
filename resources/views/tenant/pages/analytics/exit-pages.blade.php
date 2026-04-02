@extends('layouts.tenant')
@section('title', 'Exit Pages')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-log-out-circle" style="color:var(--analytics);"></i> Exit Pages</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Exit Pages</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Exits</span></div><div class="kpi-value" id="xp-exits">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Unique Exit Pages</span></div><div class="kpi-value" id="xp-unique">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Top Exit Page %</span></div><div class="kpi-value" id="xp-high">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Checkout Exits</span></div><div class="kpi-value" id="xp-checkout">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Exit Pages</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Page URL</th><th class="text-end">Exits</th><th class="text-end">% of Total</th></tr></thead>
                            <tbody id="exit-body"><tr><td colspan="4" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Exit Distribution</h5>
                    <div id="exit-chart" style="height:320px;"></div>
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
        const exits = d.top_exit_pages || d.exit_pages || [];
        const total = exits.reduce((s,p) => s + (p.sessions || p.count || p.exits || 0), 0);

        $('#xp-exits').text(EcomUtils.number(total));
        $('#xp-unique').text(exits.length);

        if (exits.length) {
            const topExitPct = total > 0 ? ((exits[0].sessions||exits[0].count||0)/total*100).toFixed(1)+'%' : '—';
            $('#xp-high').text(topExitPct);
            const checkout = exits.filter(p => (p.url||p.page||'').match(/checkout|cart|payment/i));
            $('#xp-checkout').text(checkout.reduce((s,p) => s + (p.sessions || p.count || p.exits || 0), 0));

            let h = '';
            exits.forEach((p, i) => {
                const url = p.url || p.page || p.path || '/';
                const cnt = p.sessions || p.count || p.exits || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${url}">${url.replace(/https?:\/\/[^/]+/,'')}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(cnt)}</td>
                    <td class="text-end mono" style="font-size:12px;">${total ? (cnt/total*100).toFixed(1)+'%' : '—'}</td>
                </tr>`;
            });
            $('#exit-body').html(h);

            const top = exits.slice(0,8);
            new ApexCharts(document.querySelector('#exit-chart'), {
                chart:{type:'donut',height:320,fontFamily:'Inter'},
                series:top.map(p=>p.sessions||p.count||p.exits||0),
                labels:top.map(p=>(p.url||p.page||'/').replace(/https?:\/\/[^/]+/,'').substring(0,30)),
                colors:['#dc3545','#fd7e14','#ffc107','#20c997','#0dcaf0','#6f42c1','#6c757d','#198754'],
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load exit pages data');
        $('#exit-body').html('<tr><td colspan="4" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
