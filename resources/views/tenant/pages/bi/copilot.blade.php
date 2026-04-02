@extends('layouts.tenant')
@section('title', 'AI BI Copilot')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-bot" style="color:var(--analytics);"></i> AI BI Copilot</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Copilot</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Natural Language Query --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bx bx-message-square-dots"></i> Ask Your Data</h5>
                        <p class="text-muted mb-3">Ask any business question in natural language. The AI will query your data and summarize findings.</p>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control form-control-lg" id="nl-query" placeholder="e.g. What was our best selling product last month?" autofocus>
                            <button class="btn btn-primary btn-lg" id="btn-ask"><i class="bx bx-send"></i> Ask</button>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="What is our revenue trend this month?">Revenue trend</button>
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="Which products are growing fastest?">Top growing products</button>
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="What is our customer repeat purchase rate?">Repeat rate</button>
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="Which marketing campaigns drove the most revenue?">Best campaigns</button>
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="What are the top coupon codes by revenue?">Top coupons</button>
                            <button class="btn btn-outline-secondary btn-sm quick-q" data-q="Show customer acquisition trend">Acquisition trend</button>
                        </div>
                        <div id="nl-result" style="display:none;">
                            <div class="border rounded p-4 bg-light">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="bx bx-bot font-size-24 text-primary me-2"></i>
                                    <strong>AI Answer</strong>
                                </div>
                                <div id="nl-answer" style="white-space:pre-wrap; line-height:1.7;"></div>
                            </div>
                        </div>
                        <div id="nl-loading" style="display:none;" class="text-center py-4">
                            <i class="bx bx-loader-alt bx-spin font-size-24 text-primary"></i>
                            <p class="text-muted mt-2">Analyzing your data...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Executive Briefing --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0"><i class="bx bx-briefcase-alt"></i> Executive Daily Briefing</h5>
                            <button class="btn btn-sm btn-outline-primary" id="btn-briefing"><i class="bx bx-refresh"></i> Generate Briefing</button>
                        </div>
                        <div id="briefing-content">
                            <div class="text-muted text-center py-4">Click "Generate Briefing" to get an AI-powered summary of today's business performance.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pre-Built Insights --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Quick Insights</h5>
                        <div id="quick-insights">
                            <div class="text-center py-3"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title">Recent Queries</h5>
                        <div id="recent-queries"><div class="text-muted text-center py-3">No queries yet</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' };

let queryHistory = [];

async function askQuestion(query) {
    if (!query.trim()) return;
    $('#nl-loading').show();
    $('#nl-result').hide();

    try {
        // Use the existing insights/query endpoint
        const res = await fetch(`${API}/bi/insights/query`, {
            method: 'POST',
            headers,
            body: JSON.stringify({ query: query, type: 'natural_language' })
        });
        const json = await res.json();

        if (json.success || json.data) {
            const data = json.data || json;
            let answer = '';

            if (typeof data === 'string') {
                answer = data;
            } else if (data.narrative) {
                answer = data.narrative;
            } else if (data.results) {
                answer = '📊 Query Results:\n\n';
                if (Array.isArray(data.results)) {
                    data.results.forEach((r, i) => {
                        answer += `${i+1}. ${JSON.stringify(r, null, 2)}\n`;
                    });
                } else {
                    answer = JSON.stringify(data.results, null, 2);
                }
            } else {
                answer = JSON.stringify(data, null, 2);
            }

            $('#nl-answer').text(answer);
            $('#nl-result').show();

            // Save to history
            queryHistory.unshift({ query, answer: answer.substring(0, 100) + '...', time: new Date().toLocaleTimeString() });
            renderHistory();
        } else {
            $('#nl-answer').text('⚠️ ' + (json.error || 'No results found. Try a different question.'));
            $('#nl-result').show();
        }
    } catch (e) {
        $('#nl-answer').text('⚠️ Error: ' + e.message);
        $('#nl-result').show();
    }

    $('#nl-loading').hide();
}

async function generateBriefing() {
    const btn = $('#btn-briefing');
    btn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i> Generating...');
    $('#briefing-content').html('<div class="text-center py-4"><i class="bx bx-loader-alt bx-spin font-size-24"></i></div>');

    try {
        // Fetch key data points in parallel
        const [revRes, custRes, prodRes] = await Promise.all([
            fetch(`${API}/bi/intel/revenue/command-center`, { headers }).then(r => r.json()),
            fetch(`${API}/bi/intel/customers/overview`, { headers }).then(r => r.json()),
            fetch(`${API}/bi/intel/products/leaderboard?limit=5`, { headers }).then(r => r.json()),
        ]);

        const rev  = revRes.data || {};
        const cust = custRes.data || {};
        const prod = prodRes.data || [];

        const today = rev.today || {};
        const month = rev.month || {};

        let briefing = `<div class="py-2" style="line-height:1.8;">`;
        briefing += `<h5>📊 Daily Business Briefing — ${new Date().toLocaleDateString('en-IN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</h5><hr>`;

        briefing += `<h6>💰 Revenue Snapshot</h6>`;
        briefing += `<p>Today's revenue is <strong>₹${EcomUtils.number(today.revenue || 0)}</strong> from <strong>${today.orders || 0}</strong> orders`;
        if (today.revenue_change) {
            const dir = today.revenue_change >= 0 ? '📈 up' : '📉 down';
            briefing += ` (${dir} ${Math.abs(today.revenue_change).toFixed(1)}% vs yesterday)`;
        }
        briefing += `.</p>`;

        briefing += `<p>This month: <strong>₹${EcomUtils.number(month.revenue || 0)}</strong> from <strong>${month.orders || 0}</strong> orders with an AOV of <strong>₹${EcomUtils.number(month.aov || 0)}</strong>.</p>`;

        briefing += `<h6>👥 Customer Health</h6>`;
        briefing += `<p><strong>${EcomUtils.number(cust.total_customers || 0)}</strong> total customers, <strong>${cust.active_30d || 0}</strong> active in last 30 days (${cust.active_30d_pct || 0}%). `;
        briefing += `Repeat purchase rate: <strong>${cust.repeat_purchase_rate || 0}%</strong>. `;
        briefing += `Churn risk: <strong>${cust.churn_rate_90d || 0}%</strong>.</p>`;

        if (prod.length) {
            briefing += `<h6>🏆 Top Products</h6><ol>`;
            prod.slice(0, 5).forEach(p => {
                briefing += `<li><strong>${p.name || p._id}</strong> — ₹${EcomUtils.number(p.revenue)} (${p.trend || '→'})</li>`;
            });
            briefing += `</ol>`;
        }

        briefing += `<h6>🎯 Key Actions</h6><ul>`;
        if (cust.churn_rate_90d > 10) briefing += `<li>⚠️ Churn rate at ${cust.churn_rate_90d}% — consider re-engagement campaigns</li>`;
        if (today.revenue_change && today.revenue_change < -20) briefing += `<li>⚠️ Revenue dropped ${Math.abs(today.revenue_change).toFixed(1)}% today — investigate</li>`;
        briefing += `<li>📧 ${cust.new_this_month || 0} new customers this month — nurture with welcome flows</li>`;
        briefing += `</ul>`;
        briefing += `</div>`;

        $('#briefing-content').html(briefing);
    } catch (e) {
        $('#briefing-content').html(`<div class="text-danger py-3">Error generating briefing: ${e.message}</div>`);
    }

    btn.prop('disabled', false).html('<i class="bx bx-refresh"></i> Generate Briefing');
}

async function loadQuickInsights() {
    try {
        const d = await fetch(`${API}/bi/intel/revenue/command-center`, { headers }).then(r => r.json());
        if (!d.success) throw new Error(d.error);
        const m = d.data?.month || {};
        const y = d.data?.year || {};

        let html = '';
        html += `<div class="d-flex justify-content-between py-2 border-bottom"><span>Monthly Revenue</span><strong>₹${EcomUtils.number(m.revenue || 0)}</strong></div>`;
        html += `<div class="d-flex justify-content-between py-2 border-bottom"><span>Monthly Orders</span><strong>${EcomUtils.number(m.orders || 0)}</strong></div>`;
        html += `<div class="d-flex justify-content-between py-2 border-bottom"><span>Monthly AOV</span><strong>₹${EcomUtils.number(m.aov || 0)}</strong></div>`;
        html += `<div class="d-flex justify-content-between py-2 border-bottom"><span>Yearly Revenue</span><strong>₹${EcomUtils.number(y.revenue || 0)}</strong></div>`;
        html += `<div class="d-flex justify-content-between py-2"><span>Yearly Orders</span><strong>${EcomUtils.number(y.orders || 0)}</strong></div>`;

        $('#quick-insights').html(html);
    } catch (e) {
        $('#quick-insights').html('<div class="text-muted text-center">Unable to load insights</div>');
    }
}

function renderHistory() {
    if (!queryHistory.length) return;
    let html = '';
    queryHistory.slice(0, 5).forEach(q => {
        html += `<div class="py-2 border-bottom cursor-pointer history-item" data-q="${q.query.replace(/"/g, '&quot;')}">
            <div class="fw-bold">${q.query}</div>
            <small class="text-muted">${q.time}</small>
        </div>`;
    });
    $('#recent-queries').html(html);
}

$('#btn-ask').on('click', () => askQuestion($('#nl-query').val()));
$('#nl-query').on('keypress', function(e) { if (e.which === 13) askQuestion($(this).val()); });
$('.quick-q').on('click', function() { const q = $(this).data('q'); $('#nl-query').val(q); askQuestion(q); });
$(document).on('click', '.history-item', function() { const q = $(this).data('q'); $('#nl-query').val(q); askQuestion(q); });
$('#btn-briefing').on('click', generateBriefing);

document.addEventListener('DOMContentLoaded', loadQuickInsights);
</script>
@endpush
