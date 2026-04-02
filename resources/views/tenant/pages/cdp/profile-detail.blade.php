@extends('layouts.tenant')
@section('title', 'Customer Profile')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-user-circle" style="color:var(--analytics);"></i> <span id="profile-name">Customer Profile</span></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.dashboard', $tenant->slug) }}">CDP</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tenant.cdp.profiles', $tenant->slug) }}">Profiles</a></li>
                    <li class="breadcrumb-item active">Detail</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body" id="profile-content" style="display:none;">
        {{-- Hero row --}}
        <div class="card mb-4" data-module="analytics">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold" id="profile-avatar" style="width:64px;height:64px;font-size:1.5rem;">?</div>
                    </div>
                    <div class="col">
                        <h5 class="mb-0" id="hero-name">—</h5>
                        <small class="text-muted" id="hero-email">—</small>
                        <div class="mt-1">
                            <span class="badge bg-primary" id="hero-rfm">—</span>
                            <span class="badge" id="hero-churn">—</span>
                            <span class="badge bg-info" id="hero-completeness">—</span>
                        </div>
                    </div>
                    <div class="col-auto text-end">
                        <div class="fs-4 fw-bold text-success" id="hero-ltv">₹0</div>
                        <small class="text-muted">Lifetime Value</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- Quality Flags --}}
        <div class="alert alert-warning mb-4" id="quality-flags" style="display:none;">
            <i class="bx bx-error-circle"></i> <b>Data Quality Issues:</b> <span id="quality-list"></span>
        </div>

        {{-- Computed Scores Row --}}
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">R Score</div><div class="fs-4 fw-bold" id="sc-r">—</div>
                </div></div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">F Score</div><div class="fs-4 fw-bold" id="sc-f">—</div>
                </div></div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">M Score</div><div class="fs-4 fw-bold" id="sc-m">—</div>
                </div></div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">Churn Risk</div><div class="fs-4 fw-bold" id="sc-churn">—</div>
                </div></div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">Buy Propensity</div><div class="fs-4 fw-bold" id="sc-propensity">—</div>
                </div></div>
            </div>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100" data-module="analytics"><div class="card-body py-3">
                    <div class="small text-muted">Disc. Sensitivity</div><div class="fs-4 fw-bold" id="sc-discount">—</div>
                </div></div>
            </div>
        </div>

        <div class="row g-4">
            {{-- Left Column: Layers --}}
            <div class="col-lg-7">
                {{-- Identity --}}
                <div class="card mb-4" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-fingerprint"></i> Identity</h6></div>
                    <div class="card-body"><dl class="row mb-0" id="layer-identity"></dl></div>
                </div>

                {{-- Demographics --}}
                <div class="card mb-4" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-id-card"></i> Demographics</h6></div>
                    <div class="card-body"><dl class="row mb-0" id="layer-demographics"></dl></div>
                </div>

                {{-- Transactional --}}
                <div class="card mb-4" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-cart"></i> Transactional</h6></div>
                    <div class="card-body"><dl class="row mb-0" id="layer-transactional"></dl></div>
                </div>

                {{-- Behavioural --}}
                <div class="card mb-4" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-mouse"></i> Behavioural</h6></div>
                    <div class="card-body"><dl class="row mb-0" id="layer-behavioural"></dl></div>
                </div>

                {{-- Search --}}
                <div class="card mb-4" data-module="analytics">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-search"></i> Search</h6></div>
                    <div class="card-body"><dl class="row mb-0" id="layer-search"></dl></div>
                </div>
            </div>

            {{-- Right Column: Timeline --}}
            <div class="col-lg-5">
                <div class="card" data-module="analytics" style="max-height:900px; overflow-y:auto;">
                    <div class="card-header"><h6 class="mb-0"><i class="bx bx-time-five"></i> Activity Timeline</h6></div>
                    <div class="card-body p-0" id="timeline-body">
                        <div class="text-center text-muted py-4">Loading…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="profile-loading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
        <div class="mt-2 text-muted">Loading profile…</div>
    </div>
    <div id="profile-error" style="display:none;" class="text-center py-5">
        <i class="bx bx-error-circle fs-1 text-danger"></i>
        <div class="mt-2" id="error-msg"></div>
    </div>
@endsection

@push('scripts')
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const PROFILE_ID = '{{ $profileId ?? "" }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

function dlRow(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<dt class="col-sm-5 text-muted">${label}</dt><dd class="col-sm-7">${val}</dd>`;
}

function riskColor(level) {
    return { high: 'danger', medium: 'warning', low: 'success' }[level] || 'secondary';
}

async function loadProfile() {
    try {
        const res = await fetch(`${API}/cdp/profiles/${PROFILE_ID}`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const p = json.data;

        const d = p.demographics || {};
        const t = p.transactional || {};
        const b = p.behavioural || {};
        const c = p.computed || {};
        const s = p.search || {};
        const id = p.identity || {};
        const pred = p.predictions || {};

        const name = [d.firstname, d.lastname].filter(Boolean).join(' ') || p.email;
        const initials = name.split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);

        $('#profile-name').text(name);
        document.title = `${name} — CDP`;
        $('#hero-name').text(name);
        $('#hero-email').text(p.email);
        $('#hero-ltv').text('₹' + EcomUtils.number(t.lifetime_revenue || 0));
        $('#hero-rfm').text(c.rfm_segment || 'N/A');
        $('#hero-churn').text(`Churn: ${((c.churn_risk || 0) * 100).toFixed(0)}%`).addClass(`bg-${riskColor(c.churn_risk_level)}`);
        $('#hero-completeness').text(`Completeness: ${p.profile_completeness || 0}%`);
        $('#profile-avatar').text(initials);

        // Computed scores
        $('#sc-r').text(c.r_score || '—');
        $('#sc-f').text(c.f_score || '—');
        $('#sc-m').text(c.m_score || '—');
        $('#sc-churn').text(c.churn_risk ? `${(c.churn_risk * 100).toFixed(0)}%` : '—');
        $('#sc-propensity').text(c.purchase_propensity ? `${(c.purchase_propensity * 100).toFixed(0)}%` : '—');
        $('#sc-discount').text(c.discount_sensitivity ? `${(c.discount_sensitivity * 100).toFixed(0)}%` : '—');

        // Quality flags
        if (p.data_quality_flags && p.data_quality_flags.length) {
            $('#quality-list').text(p.data_quality_flags.join(', '));
            $('#quality-flags').show();
        }

        // Identity layer
        $('#layer-identity').html(
            dlRow('Email', p.email) +
            dlRow('Phone', p.phone) +
            dlRow('Magento ID', p.magento_customer_id) +
            dlRow('CDP UUID', p.cdp_uuid) +
            dlRow('Sessions', (p.known_sessions || []).length) +
            dlRow('Merged Profiles', (p.merged_profiles || []).length)
        );

        // Demographics
        $('#layer-demographics').html(
            dlRow('First Name', d.firstname) +
            dlRow('Last Name', d.lastname) +
            dlRow('Gender', d.gender) +
            dlRow('Date of Birth', d.dob) +
            dlRow('City', d.city) +
            dlRow('Region', d.region) +
            dlRow('Country', d.country) +
            dlRow('Postcode', d.postcode)
        );

        // Transactional
        $('#layer-transactional').html(
            dlRow('Total Orders', t.total_orders) +
            dlRow('Lifetime Revenue', '₹' + EcomUtils.number(t.lifetime_revenue || 0)) +
            dlRow('AOV', '₹' + EcomUtils.number(t.aov || 0)) +
            dlRow('First Order', t.first_order_date) +
            dlRow('Last Order', t.last_order_date) +
            dlRow('Avg Days Between', t.avg_days_between_orders) +
            dlRow('Top Categories', (t.top_categories || []).map(c => `${c.name} (${c.count})`).join(', ')) +
            dlRow('Top Brands', (t.top_brands || []).map(b => `${b.name} (${b.count})`).join(', ')) +
            dlRow('Coupon Usage', t.coupon_usage_rate ? `${(t.coupon_usage_rate * 100).toFixed(0)}%` : '—') +
            dlRow('Top Payment', t.preferred_payment)
        );

        // Behavioural
        $('#layer-behavioural').html(
            dlRow('Total Sessions', b.total_sessions) +
            dlRow('Total Pageviews', b.total_pageviews) +
            dlRow('Primary Device', b.primary_device) +
            dlRow('Peak Hour', b.peak_hour !== undefined ? `${b.peak_hour}:00` : '—') +
            dlRow('Last Seen', b.last_seen ? new Date(b.last_seen).toLocaleString('en-IN') : '—')
        );

        // Search
        $('#layer-search').html(
            dlRow('Total Searches', s.total_searches) +
            dlRow('Top Queries', (s.top_queries || []).join(', ') || '—')
        );

        $('#profile-loading').hide();
        $('#profile-content').show();

        // Load timeline
        loadTimeline();
    } catch (e) {
        console.error(e);
        $('#profile-loading').hide();
        $('#error-msg').text(e.message);
        $('#profile-error').show();
    }
}

async function loadTimeline() {
    try {
        const res = await fetch(`${API}/cdp/profiles/${PROFILE_ID}/timeline`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const events = json.data || [];

        if (!events.length) {
            $('#timeline-body').html('<div class="text-center text-muted py-4">No events found.</div>');
            return;
        }

        let html = '<ul class="list-group list-group-flush">';
        events.forEach(ev => {
            html += `<li class="list-group-item px-3 py-2">
                <div class="d-flex align-items-start">
                    <span class="me-2" style="font-size:1.1rem;">${ev.icon || '📌'}</span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">${ev.label || ev.type}</div>
                        <div class="text-muted" style="font-size:0.78rem;">${ev.detail || ''}</div>
                    </div>
                    <small class="text-muted text-nowrap ms-2">${ev.when ? new Date(ev.when).toLocaleString('en-IN', {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : ''}</small>
                </div>
            </li>`;
        });
        html += '</ul>';
        $('#timeline-body').html(html);
    } catch (e) {
        $('#timeline-body').html('<div class="text-center text-danger py-4">Timeline error.</div>');
    }
}

document.addEventListener('DOMContentLoaded', loadProfile);
</script>
@endpush
