@extends('layouts.tenant')
@section('title', 'Marketing Attribution')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-target-lock" style="color:var(--analytics);"></i> Marketing → Revenue Attribution</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Attribution</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Campaign Revenue</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-camp-rev">—</div><div class="kpi-sub" id="kpi-camp-pct"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Store Revenue</span><span class="kpi-icon"><i class="bx bx-store" style="color:#34c38f;"></i></span></div>
                <div class="kpi-value" id="kpi-store-rev">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Campaigns</span><span class="kpi-icon orders"><i class="bx bx-send"></i></span></div>
                <div class="kpi-value" id="kpi-campaigns">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Revenue/Campaign</span><span class="kpi-icon conversion"><i class="bx bx-bar-chart"></i></span></div>
                <div class="kpi-value" id="kpi-avg-rev">—</div></div>
            </div>
        </div>

        {{-- Marketing Funnel --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-5">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Marketing Funnel</h5>
                        <div id="funnel-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-7">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Campaign Revenue Ranking</h5>
                        <div id="revenue-chart" style="height:340px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Campaign Table --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Campaign Performance</h5>
                        <div class="table-responsive">
                            <table class="table table-nowrap table-sm mb-0" id="campaign-table">
                                <thead>
                                    <tr>
                                        <th>Campaign</th>
                                        <th class="text-end">Sent</th>
                                        <th class="text-end">Open %</th>
                                        <th class="text-end">Click %</th>
                                        <th class="text-end">Conv %</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">ROAS</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

async function loadAttribution() {
    try {
        const res = await fetch(`${API}/bi/intel/cross/marketing-attribution`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data;

        $('#kpi-camp-rev').text('₹' + EcomUtils.number(d.total_campaign_revenue));
        $('#kpi-camp-pct').text(d.attributed_pct + '% of total revenue');
        $('#kpi-store-rev').text('₹' + EcomUtils.number(d.total_store_revenue));
        $('#kpi-campaigns').text(d.total_campaigns);
        $('#kpi-avg-rev').text('₹' + EcomUtils.number(d.avg_campaign_revenue));

        // Funnel
        const f = d.funnel || {};
        new ApexCharts(document.querySelector('#funnel-chart'), {
            chart: { type: 'bar', height: 340 },
            series: [{ name: 'Count', data: [f.sent, f.delivered, f.opened, f.clicked, f.converted] }],
            xaxis: { categories: ['Sent', 'Delivered', 'Opened', 'Clicked', 'Converted'] },
            colors: ['#556ee6'],
            plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
            dataLabels: { enabled: true, formatter: v => EcomUtils.number(v) },
        }).render();

        // Top campaigns by revenue
        const camps = (d.campaigns || []).slice(0, 10);
        new ApexCharts(document.querySelector('#revenue-chart'), {
            chart: { type: 'bar', height: 340 },
            series: [{ name: 'Revenue', data: camps.map(c => c.revenue) }],
            xaxis: { categories: camps.map(c => EcomUtils.truncate(c.name, 20)), labels: { rotate: -45 } },
            colors: ['#34c38f'],
            plotOptions: { bar: { borderRadius: 3 } },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
            dataLabels: { enabled: false },
        }).render();

        // Table
        let html = '';
        (d.campaigns || []).forEach(c => {
            const roasCls = c.roas >= 5 ? 'text-success' : c.roas >= 1 ? '' : 'text-danger';
            html += `<tr>
                <td>${EcomUtils.truncate(c.name, 30)}</td>
                <td class="text-end">${EcomUtils.number(c.sent)}</td>
                <td class="text-end">${c.open_rate}%</td>
                <td class="text-end">${c.click_rate}%</td>
                <td class="text-end">${c.conversion_rate}%</td>
                <td class="text-end">₹${EcomUtils.number(c.revenue)}</td>
                <td class="text-end ${roasCls}">${c.roas}x</td>
            </tr>`;
        });
        $('#campaign-table tbody').html(html || '<tr><td colspan="7" class="text-muted text-center">No campaigns</td></tr>');
    } catch (e) {
        console.error('Attribution:', e);
    }
}

document.addEventListener('DOMContentLoaded', loadAttribution);
</script>
@endpush
