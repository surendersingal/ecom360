@extends('layouts.tenant')
@section('title', 'Customer Profiles')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-group" style="color:var(--analytics);"></i> Customer Profiles</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item active">Profiles</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- Filters --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" id="filter-search" class="form-control" placeholder="Email, name, or phone…">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">RFM Segment</label>
                        <select id="filter-rfm" class="form-select">
                            <option value="">All</option>
                            <option>Champion</option><option>Loyal</option><option>Potential Loyal</option>
                            <option>New Customer</option><option>Promising</option><option>Need Attention</option>
                            <option>About to Sleep</option><option>At Risk</option><option>Cannot Lose</option>
                            <option>Hibernating</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Churn Risk</label>
                        <select id="filter-churn" class="form-select">
                            <option value="">All</option>
                            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Min LTV (₹)</label>
                        <input type="number" id="filter-ltv" class="form-control" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="loadProfiles(1)"><i class="bx bx-search"></i> Filter</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Results --}}
        <div class="card" data-module="analytics">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Profiles <span class="badge bg-secondary ms-2" id="total-count">0</span></h5>
                    <div id="pagination-info" class="text-muted small"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="profiles-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>RFM</th>
                                <th>Orders</th>
                                <th>LTV</th>
                                <th>Churn Risk</th>
                                <th>Completeness</th>
                                <th>Last Seen</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="profiles-body">
                            <tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-center mt-3" id="pagination-container"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const SLUG = '{{ $tenant->slug }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

// Set initial filter from query params
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('rfm_segment')) $('#filter-rfm').val(urlParams.get('rfm_segment'));

function riskBadge(level) {
    const colors = { high: 'danger', medium: 'warning', low: 'success' };
    return `<span class="badge bg-${colors[level] || 'secondary'}">${(level || 'N/A').charAt(0).toUpperCase() + (level || '').slice(1)}</span>`;
}

function rfmBadge(segment) {
    const icons = {'Champion':'🏆','Loyal':'💛','Potential Loyal':'🚀','New Customer':'🆕','Promising':'🔮','Need Attention':'💤','About to Sleep':'😴','At Risk':'⚠️','Cannot Lose':'❌','Hibernating':'👻'};
    return `${icons[segment] || '📊'} ${segment || 'N/A'}`;
}

async function loadProfiles(page = 1) {
    const params = new URLSearchParams({
        page,
        per_page: 25,
        search: $('#filter-search').val() || '',
        rfm_segment: $('#filter-rfm').val() || '',
        churn_risk: $('#filter-churn').val() || '',
        min_ltv: $('#filter-ltv').val() || '',
    });

    try {
        const res = await fetch(`${API}/cdp/profiles?${params}`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const { profiles, total, page: pg, total_pages } = json.data;

        $('#total-count').text(EcomUtils.number(total));
        $('#pagination-info').text(`Page ${pg} of ${total_pages}`);

        if (!profiles.length) {
            $('#profiles-body').html('<tr><td colspan="8" class="text-center text-muted py-4">No profiles found. Click "Rebuild All Profiles" on the CDP Dashboard.</td></tr>');
            return;
        }

        let html = '';
        profiles.forEach(p => {
            const d = p.demographics || {};
            const t = p.transactional || {};
            const c = p.computed || {};
            const b = p.behavioural || {};
            const name = [d.firstname, d.lastname].filter(Boolean).join(' ') || p.email;
            const lastSeen = b.last_seen ? new Date(b.last_seen).toLocaleDateString('en-IN') : '—';

            html += `<tr style="cursor:pointer;" onclick="window.location='{{ route('tenant.cdp.profiles', $tenant->slug) }}/${p._id}'">
                <td>
                    <div class="fw-semibold">${name}</div>
                    <small class="text-muted">${p.email}</small>
                </td>
                <td>${rfmBadge(c.rfm_segment)}</td>
                <td>${t.total_orders || 0}</td>
                <td>₹${EcomUtils.number(t.lifetime_revenue || 0)}</td>
                <td>${riskBadge(c.churn_risk_level)}</td>
                <td>
                    <div class="progress" style="height:6px; width:80px;">
                        <div class="progress-bar" style="width:${p.profile_completeness || 0}%"></div>
                    </div>
                    <small>${p.profile_completeness || 0}%</small>
                </td>
                <td>${lastSeen}</td>
                <td><i class="bx bx-chevron-right text-muted"></i></td>
            </tr>`;
        });
        $('#profiles-body').html(html);

        // Pagination
        let pagHtml = '';
        if (total_pages > 1) {
            pagHtml += `<nav><ul class="pagination pagination-sm mb-0">`;
            for (let i = 1; i <= Math.min(total_pages, 10); i++) {
                pagHtml += `<li class="page-item ${i == pg ? 'active' : ''}"><a class="page-link" href="#" onclick="loadProfiles(${i}); return false;">${i}</a></li>`;
            }
            pagHtml += `</ul></nav>`;
        }
        $('#pagination-container').html(pagHtml);
    } catch (e) {
        console.error('Profiles error:', e);
        $('#profiles-body').html(`<tr><td colspan="8" class="text-center text-danger py-4">Error: ${e.message}</td></tr>`);
    }
}

document.addEventListener('DOMContentLoaded', () => loadProfiles(1));
</script>
@endpush
