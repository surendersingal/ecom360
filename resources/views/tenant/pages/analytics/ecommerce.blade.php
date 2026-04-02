@extends('layouts.tenant')
@section('title', 'Ecommerce Overview')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-cart" style="color:var(--analytics);"></i> Ecommerce Overview</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Ecommerce</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Revenue</span></div><div class="kpi-value" id="ec-rev">—</div></div></div>
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Orders</span></div><div class="kpi-value" id="ec-orders">—</div></div></div>
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">AOV</span></div><div class="kpi-value" id="ec-aov">—</div></div></div>
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Conversion Rate</span></div><div class="kpi-value" id="ec-cr">—</div></div></div>
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Items / Order</span></div><div class="kpi-value" id="ec-items">—</div></div></div>
            <div class="col-xl-2 col-md-4"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Cart Abandon %</span></div><div class="kpi-value" id="ec-abandon">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Revenue & Orders Trend</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-primary active" data-metric="both">Both</button>
                            <button class="btn btn-outline-primary" data-metric="revenue">Revenue</button>
                            <button class="btn btn-outline-primary" data-metric="orders">Orders</button>
                        </div>
                    </div>
                    <div id="ec-trend-chart" style="height:320px;"></div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Revenue by Channel</h5>
                    <div id="ec-channel-chart" style="height:320px;"></div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Products by Revenue</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Product</th><th class="text-end">Revenue</th><th class="text-end">Qty Sold</th><th class="text-end">AOV</th></tr></thead>
                            <tbody id="ec-prod-body"><tr><td colspan="5" class="text-center py-3"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top Categories by Revenue</h5>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Category</th><th class="text-end">Revenue</th><th class="text-end">Orders</th><th class="text-end">Conv. Rate</th></tr></thead>
                            <tbody id="ec-cat-body"><tr><td colspan="5" class="text-center py-3"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
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
    let trendChart;

    $.when(
        $.get(API + '/revenue', { date_range: range }).catch(()=>({})),
        $.get(API + '/products', { date_range: range }).catch(()=>({})),
        $.get(API + '/campaigns', { date_range: range }).catch(()=>({})),
        $.get(API + '/sessions', { date_range: range }).catch(()=>({}))
    ).then(function(revRes, prodRes, campRes, sessRes) {
        const rev = revRes[0]?.data || revRes[0] || {};
        const prod = prodRes[0]?.data || prodRes[0] || {};
        const camp = campRes[0]?.data || campRes[0] || {};
        const sessData = sessRes[0]?.data || sessRes[0] || {};

        // KPIs — revenue API: { daily: { total_revenue, total_orders, average_order_value } }
        const daily = rev.daily || {};
        const totalOrders = daily.total_orders || rev.total_orders || 0;
        const totalRev = daily.total_revenue || rev.total_revenue || 0;
        const totalSessions = (sessData.metrics || {}).total_sessions || 0;
        $('#ec-rev').text('$' + EcomUtils.number(totalRev));
        $('#ec-orders').text(EcomUtils.number(totalOrders));
        $('#ec-aov').text('$' + (daily.average_order_value || rev.average_order_value || 0).toFixed(2));
        // Conversion rate: orders / sessions
        const cr = totalSessions > 0 ? (totalOrders / totalSessions * 100) : 0;
        $('#ec-cr').text(cr > 0 ? cr.toFixed(2) + '%' : '—');
        // Items per order — not in API
        $('#ec-items').text(rev.items_per_order ? rev.items_per_order.toFixed(1) : '—');
        // Cart abandonment rate — compute from products API if available
        const cartAband = prod.cart_abandonment || [];
        if (cartAband.length) {
            const totalCarts = cartAband.reduce((s,c)=>s+(c.cart_adds||0),0);
            const totalPurchases = cartAband.reduce((s,c)=>s+(c.purchases||0),0);
            const abandRate = totalCarts > 0 ? ((totalCarts - totalPurchases) / totalCarts * 100) : 0;
            $('#ec-abandon').text(abandRate > 0 ? abandRate.toFixed(1) + '%' : '—');
        } else {
            $('#ec-abandon').text('—');
        }

        // Revenue trend — revenue API: { daily: { dates[], revenues[], orders[] } } (parallel arrays)
        const dates = daily.dates || [];
        const revenues = daily.revenues || [];
        const orders = daily.orders || [];
        if (dates.length) {
            trendChart = new ApexCharts(document.querySelector('#ec-trend-chart'), {
                chart:{type:'area',height:320,fontFamily:'Inter',toolbar:{show:false}},
                series:[
                    {name:'Revenue',type:'area',data:revenues},
                    {name:'Orders',type:'line',data:orders}
                ],
                xaxis:{categories:dates,tickAmount:Math.min(dates.length,12)},
                yaxis:[
                    {title:{text:'Revenue ($)'},labels:{formatter:v=>'$'+EcomUtils.number(v)}},
                    {opposite:true,title:{text:'Orders'},labels:{formatter:v=>Math.round(v)}}
                ],
                colors:['#059669','#1A56DB'],
                fill:{type:['gradient','none'],gradient:{opacityFrom:0.3,opacityTo:0.05}},
                stroke:{curve:'smooth',width:[2,2]},
                dataLabels:{enabled:false},
            });
            trendChart.render();
        }

        // Metric toggle
        $('[data-metric]').on('click', function() {
            $('[data-metric]').removeClass('active'); $(this).addClass('active');
            if (!trendChart || !dates.length) return;
            const m = $(this).data('metric');
            if (m==='revenue') trendChart.updateSeries([{name:'Revenue',type:'area',data:revenues}]);
            else if (m==='orders') trendChart.updateSeries([{name:'Orders',type:'area',data:orders}]);
            else trendChart.updateSeries([{name:'Revenue',type:'area',data:revenues},{name:'Orders',type:'line',data:orders}]);
        });

        // Channel revenue — campaigns API: { channel_attribution: { channels: [{channel, revenue, conversions}] } }
        const ca = camp.channel_attribution || {};
        const channels = ca.channels || camp.channels || [];
        if (channels.length) {
            new ApexCharts(document.querySelector('#ec-channel-chart'), {
                chart:{type:'donut',height:320,fontFamily:'Inter'},
                series:channels.filter(c=>c.revenue).map(c=>c.revenue),
                labels:channels.filter(c=>c.revenue).map(c=>c.channel||c.name||'?'),
                legend:{position:'bottom',fontSize:'10px'},
            }).render();
        }

        // Top products — products API: { performance: [{product_name, revenue, purchases}], top_by_views: [...] }
        const products = prod.performance || prod.top_by_views || prod.top_products || [];
        if (products.length) {
            let h = '';
            products.slice(0,10).forEach((p, i) => {
                const qty = p.purchases || p.quantity || p.qty_sold || p.count || 0;
                const revenue = p.revenue || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;">${p.product_name||p.name||p.sku||'—'}</td>
                    <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(revenue)}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(qty)}</td>
                    <td class="text-end mono" style="font-size:12px;">$${(revenue&&qty ? (revenue/qty).toFixed(2) : '0.00')}</td>
                </tr>`;
            });
            $('#ec-prod-body').html(h);
        }

        // Top categories — use /categories endpoint or fallback to category data in products
        $.get(API + '/categories', { date_range: range }).then(function(catRes) {
            const catData = catRes?.data || catRes || {};
            const cats = catData.category_views || catData.categories || [];
            if (cats.length) {
                let h = '';
                cats.slice(0,10).forEach((c, i) => {
                    h += `<tr>
                        <td class="text-muted" style="font-size:11px;">${i+1}</td>
                        <td style="font-size:12px;">${c.category||c.name||c._id||'—'}</td>
                        <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(c.views||0)} views</td>
                        <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(c.unique_visitors||0)}</td>
                        <td class="text-end mono" style="font-size:12px;">—</td>
                    </tr>`;
                });
                $('#ec-cat-body').html(h);
            } else {
                $('#ec-cat-body').html('<tr><td colspan="5" class="text-muted text-center py-3">No category data</td></tr>');
            }
        }).catch(()=> {
            $('#ec-cat-body').html('<tr><td colspan="5" class="text-muted text-center py-3">No category data</td></tr>');
        });
    });
})();
</script>
@endsection
