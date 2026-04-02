@extends('layouts.tenant')
@section('title', 'Chatbot Impact')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-message-dots" style="color:var(--analytics);"></i> Chatbot → Conversion Impact</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Chatbot Impact</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Conversations</span><span class="kpi-icon visitors"><i class="bx bx-chat"></i></span></div>
                <div class="kpi-value" id="kpi-convos">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Resolution Rate</span><span class="kpi-icon conversion"><i class="bx bx-check-circle"></i></span></div>
                <div class="kpi-value" id="kpi-resolution">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Satisfaction</span><span class="kpi-icon"><i class="bx bx-happy" style="color:#34c38f;"></i></span></div>
                <div class="kpi-value" id="kpi-csat">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Chatbot Revenue</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-chat-rev">—</div><div class="kpi-sub" id="kpi-chat-share"></div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">AOV Lift</span><span class="kpi-icon"><i class="bx bx-rocket" style="color:#556ee6;"></i></span></div>
                <div class="kpi-value" id="kpi-aov-lift">—</div><div class="kpi-sub">vs non-chat customers</div></div>
            </div>
        </div>

        {{-- Comparison + Actions --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">AOV Comparison: Chat vs Non-Chat</h5>
                        <div id="aov-chart" style="height:300px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Chatbot Actions</h5>
                        <div id="actions-chart" style="height:300px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Intent Distribution --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Top Conversation Intents</h5>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" id="intents-table">
                                <thead><tr><th>Intent</th><th class="text-end">Count</th><th>Distribution</th></tr></thead>
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

async function loadChatbot() {
    try {
        const res = await fetch(`${API}/bi/intel/cross/chatbot-impact`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data;
        const s = d.summary || {};
        const r = d.revenue_impact || {};

        $('#kpi-convos').text(EcomUtils.number(s.total_conversations));
        $('#kpi-resolution').text(s.resolution_rate + '%');
        $('#kpi-csat').text(s.avg_satisfaction ? s.avg_satisfaction + '/5' : 'N/A');
        $('#kpi-chat-rev').text('₹' + EcomUtils.number(r.chatbot_customer_revenue));
        $('#kpi-chat-share').text(r.revenue_share + '% of total');
        const liftCls = r.aov_lift_pct >= 0 ? 'text-success' : 'text-danger';
        $('#kpi-aov-lift').html(`<span class="${liftCls}">${r.aov_lift_pct >= 0 ? '+' : ''}${r.aov_lift_pct}%</span>`);

        // AOV comparison bar
        new ApexCharts(document.querySelector('#aov-chart'), {
            chart: { type: 'bar', height: 300 },
            series: [{ name: 'AOV', data: [r.chatbot_customer_aov, r.non_chat_aov] }],
            xaxis: { categories: ['Chat Customers', 'Non-Chat Customers'] },
            colors: ['#556ee6', '#74788d'],
            plotOptions: { bar: { distributed: true, borderRadius: 6, columnWidth: '45%' } },
            legend: { show: false },
            dataLabels: { enabled: true, formatter: v => '₹' + EcomUtils.number(v) },
            tooltip: { y: { formatter: v => '₹' + EcomUtils.number(v) } },
        }).render();

        // Actions chart
        const actions = d.actions || [];
        if (actions.length) {
            new ApexCharts(document.querySelector('#actions-chart'), {
                chart: { type: 'donut', height: 300 },
                series: actions.map(a => a.count),
                labels: actions.map(a => a.action.replace(/_/g, ' ')),
                colors: ['#556ee6', '#34c38f', '#f1b44c', '#50a5f1', '#e83e8c'],
            }).render();
        } else {
            $('#actions-chart').html('<div class="text-muted text-center py-5">No chatbot actions recorded</div>');
        }

        // Intents table
        const intents = d.intents || [];
        const maxCount = Math.max(...intents.map(i => i.count), 1);
        let html = '';
        intents.forEach(i => {
            const pct = (i.count / maxCount * 100).toFixed(0);
            html += `<tr>
                <td>${i.intent}</td>
                <td class="text-end">${EcomUtils.number(i.count)}</td>
                <td><div class="progress" style="height:18px;"><div class="progress-bar bg-primary" style="width:${pct}%">${i.count}</div></div></td>
            </tr>`;
        });
        $('#intents-table tbody').html(html || '<tr><td colspan="3" class="text-muted text-center">No intent data</td></tr>');
    } catch (e) {
        console.error('Chatbot:', e);
    }
}

document.addEventListener('DOMContentLoaded', loadChatbot);
</script>
@endpush
