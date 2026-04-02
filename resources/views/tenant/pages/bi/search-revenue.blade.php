@extends('layouts.tenant')
@section('title', 'Search → Revenue')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-search-alt" style="color:var(--analytics);"></i> Search → Revenue Correlation</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Search Revenue</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Searches</span><span class="kpi-icon visitors"><i class="bx bx-search"></i></span></div>
                <div class="kpi-value" id="kpi-searches">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Click Rate</span><span class="kpi-icon conversion"><i class="bx bx-pointer"></i></span></div>
                <div class="kpi-value" id="kpi-ctr">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Conversion Rate</span><span class="kpi-icon orders"><i class="bx bx-cart-alt"></i></span></div>
                <div class="kpi-value" id="kpi-conv">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Avg Response</span><span class="kpi-icon"><i class="bx bx-timer" style="color:#50a5f1;"></i></span></div>
                <div class="kpi-value" id="kpi-response">—</div></div>
            </div>
            <div class="col-xl col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Zero Results</span><span class="kpi-icon"><i class="bx bx-x-circle" style="color:#f46a6a;"></i></span></div>
                <div class="kpi-value" id="kpi-zero">—</div></div>
            </div>
        </div>

        {{-- Top Queries + Zero Results --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-8">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Top Search Queries</h5>
                        <div class="table-responsive">
                            <table class="table table-nowrap table-sm mb-0" id="queries-table">
                                <thead>
                                    <tr>
                                        <th>Query</th>
                                        <th class="text-end">Searches</th>
                                        <th class="text-end">Clicks</th>
                                        <th class="text-end">Click %</th>
                                        <th class="text-end">Conv</th>
                                        <th class="text-end">Conv %</th>
                                        <th class="text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card border-danger" data-module="analytics" style="height:100%;">
                    <div class="card-body">
                        <h5 class="card-title text-danger"><i class="bx bx-x-circle"></i> Zero-Result Queries</h5>
                        <p class="text-muted mb-3">Queries where users found nothing — potential product gaps.</p>
                        <div id="zero-list"></div>
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
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

async function loadSearch() {
    try {
        const res = await fetch(`${API}/bi/intel/cross/search-revenue`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data;
        const s = d.summary || {};

        $('#kpi-searches').text(EcomUtils.number(s.total_searches));
        $('#kpi-ctr').text(s.click_rate + '%');
        $('#kpi-conv').text(s.conversion_rate + '%');
        $('#kpi-response').text(s.avg_response_ms + 'ms');
        $('#kpi-zero').text(s.zero_result_rate + '%');

        // Queries table
        let html = '';
        (d.top_queries || []).forEach(q => {
            html += `<tr>
                <td>${q.query}</td>
                <td class="text-end">${q.searches}</td>
                <td class="text-end">${q.clicks}</td>
                <td class="text-end">${q.click_rate}%</td>
                <td class="text-end">${q.conversions}</td>
                <td class="text-end">${q.conversion_rate}%</td>
                <td class="text-end">₹${EcomUtils.number(q.revenue)}</td>
            </tr>`;
        });
        $('#queries-table tbody').html(html || '<tr><td colspan="7" class="text-muted text-center">No search data</td></tr>');

        // Zero results
        let zeroHtml = '';
        (d.zero_results || []).forEach(z => {
            zeroHtml += `<div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>"${z.query}"</span><span class="badge bg-danger">${z.count} searches</span></div>`;
        });
        $('#zero-list').html(zeroHtml || '<div class="text-muted">No zero-result queries</div>');
    } catch (e) {
        console.error('Search Revenue:', e);
    }
}

document.addEventListener('DOMContentLoaded', loadSearch);
</script>
@endpush
