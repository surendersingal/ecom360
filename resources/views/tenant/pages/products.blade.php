@extends('layouts.tenant')

@section('title', 'Product Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Product Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Products</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Top Products by Revenue</h4>
                    <div id="top-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Cart Abandonment</h4>
                    <div id="abandon-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Product Performance</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-nowrap mb-0" id="product-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Views</th>
                                    <th class="text-end">Cart Adds</th>
                                    <th class="text-end">Purchases</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">View→Cart</th>
                                    <th class="text-end">Cart→Buy</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($performance as $p)
                                <tr>
                                    <td>{{ $p['product_name'] ?? $p['product_id'] ?? 'Unknown' }}</td>
                                    <td class="text-end">{{ number_format($p['views'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($p['cart_adds'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($p['purchases'] ?? 0) }}</td>
                                    <td class="text-end">${{ number_format($p['revenue'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($p['view_to_cart_rate'] ?? 0, 1) }}%</td>
                                    <td class="text-end">{{ number_format($p['cart_to_purchase_rate'] ?? 0, 1) }}%</td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="text-center text-muted">No product data yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $tpNames = collect($topProducts)->pluck('product_name', 'product_id')->take(10)->values()->toArray();
        $tpRev   = collect($topProducts)->pluck('revenue')->take(10)->toArray();
        $abNames = collect($abandoned)->pluck('product_name', 'product_id')->take(10)->values()->toArray();
        $abRates = collect($abandoned)->pluck('abandonment_rate')->take(10)->toArray();
    @endphp
    new ApexCharts(document.querySelector("#top-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [{ name: 'Revenue', data: @json(count($tpRev) ? $tpRev : [0]) }],
        xaxis: { categories: @json(count($tpNames) ? $tpNames : ['No Data']) },
        plotOptions: { bar: { horizontal: true } },
        colors: ['#34c38f'],
        dataLabels: { enabled: true, formatter: function(v) { return '$' + v.toFixed(0); } }
    }).render();

    new ApexCharts(document.querySelector("#abandon-chart"), {
        chart: { type: 'bar', height: 350, toolbar: { show: false } },
        series: [{ name: 'Abandon Rate %', data: @json(count($abRates) ? $abRates : [0]) }],
        xaxis: { categories: @json(count($abNames) ? $abNames : ['No Data']) },
        plotOptions: { bar: { horizontal: true } },
        colors: ['#f46a6a'],
        dataLabels: { enabled: true, formatter: function(v) { return v.toFixed(1) + '%'; } }
    }).render();
</script>
@endsection