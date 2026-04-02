@extends('layouts.tenant')
@section('title', 'Product Analytics')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-package" style="color:var(--analytics);"></i> Products</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Products</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Products Viewed</span></div><div class="kpi-value" id="pr-viewed">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Products Purchased</span></div><div class="kpi-value" id="pr-purchased">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">View-to-Purchase %</span></div><div class="kpi-value" id="pr-v2p">—</div></div></div>
            <div class="col-xl-3 col-md-6"><div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg. Product Revenue</span></div><div class="kpi-value" id="pr-avgrev">—</div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Product Performance</h5>
                        <input type="text" class="form-control form-control-sm" id="prod-search" placeholder="Search products..." style="width:220px;">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Product</th><th>SKU</th><th class="text-end">Views</th><th class="text-end">Add to Cart</th><th class="text-end">Purchases</th><th class="text-end">Revenue</th><th class="text-end">Conv. Rate</th></tr></thead>
                            <tbody id="pr-body"><tr><td colspan="8" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i></td></tr></tbody>
                        </table>
                    </div>
                </div></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top 10 by Revenue</h5>
                    <div id="pr-rev-chart" style="height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics"><div class="card-body">
                    <h5 class="card-title">Top 10 by Quantity</h5>
                    <div id="pr-qty-chart" style="height:300px;"></div>
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

    $.get(API + '/products', { date_range: range }).then(function(res) {
        const d = res?.data || res || {};
        const products = d.performance || d.top_products || d.products || d.product_performance || d.top_by_views || [];
        const totalViewed = products.reduce((s,p)=>s+(p.views||0),0);
        const totalPurchased = products.reduce((s,p)=>s+(p.purchases||p.quantity||0),0);
        const totalRev = products.reduce((s,p)=>s+(p.revenue||0),0);

        $('#pr-viewed').text(EcomUtils.number(totalViewed));
        $('#pr-purchased').text(EcomUtils.number(totalPurchased));
        $('#pr-v2p').text(totalViewed ? ((totalPurchased/totalViewed)*100).toFixed(1)+'%' : '—');
        $('#pr-avgrev').text(products.length ? '$'+(totalRev/products.length).toFixed(2) : '—');

        if (products.length) {
            let h = '';
            products.forEach((p, i) => {
                const views = p.views || 0;
                const purch = p.purchases || p.quantity || 0;
                h += `<tr>
                    <td class="text-muted" style="font-size:11px;">${i+1}</td>
                    <td style="font-size:12px;font-weight:500;">${p.name||p.product_name||'—'}</td>
                    <td style="font-size:11px;color:#6B7280;">${p.sku||p.product_id||'—'}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(views)}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(p.cart_adds||p.add_to_cart||0)}</td>
                    <td class="text-end mono" style="font-size:12px;">${EcomUtils.number(purch)}</td>
                    <td class="text-end mono" style="font-size:12px;">$${EcomUtils.number(p.revenue||0)}</td>
                    <td class="text-end mono" style="font-size:12px;">${views?(purch/views*100).toFixed(1)+'%':'—'}</td>
                </tr>`;
            });
            $('#pr-body').html(h);

            $('#prod-search').on('input', function() {
                const q = this.value.toLowerCase();
                $('#pr-body tr').each(function() { $(this).toggle($(this).text().toLowerCase().includes(q)); });
            });

            // Revenue chart
            const topRev = [...products].sort((a,b)=>(b.revenue||0)-(a.revenue||0)).slice(0,10);
            new ApexCharts(document.querySelector('#pr-rev-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Revenue',data:topRev.map(p=>p.revenue||0)}],
                xaxis:{categories:topRev.map(p=>(p.name||p.product_name||'?').substring(0,18))},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#059669'],dataLabels:{enabled:false},
            }).render();

            // Qty chart
            const topQty = [...products].sort((a,b)=>(b.purchases||b.quantity||0)-(a.purchases||a.quantity||0)).slice(0,10);
            new ApexCharts(document.querySelector('#pr-qty-chart'), {
                chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                series:[{name:'Quantity',data:topQty.map(p=>p.purchases||p.quantity||0)}],
                xaxis:{categories:topQty.map(p=>(p.name||p.product_name||'?').substring(0,18))},
                plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'55%'}},
                colors:['#1A56DB'],dataLabels:{enabled:false},
            }).render();
        }
    }).catch(function(){
        console.warn('Failed to load products data');
        $('#pr-body').html('<tr><td colspan="8" class="text-center text-muted py-3">Unable to load data. Please try again.</td></tr>');
    });
})();
</script>
@endsection
