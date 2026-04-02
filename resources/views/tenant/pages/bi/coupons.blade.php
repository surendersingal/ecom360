@extends('layouts.tenant')
@section('title', 'Coupon Intelligence')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-purchase-tag" style="color:var(--analytics);"></i> Coupon Intelligence</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Business Intel</li>
                    <li class="breadcrumb-item active">Coupons</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="e360-analytics-body">
        {{-- KPIs --}}
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Coupon Usage Rate</span><span class="kpi-icon conversion"><i class="bx bx-trending-up"></i></span></div>
                <div class="kpi-value" id="kpi-usage-rate">—</div><div class="kpi-sub" id="kpi-usage-sub"></div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Coupon Revenue</span><span class="kpi-icon revenue"><i class="bx bx-rupee"></i></span></div>
                <div class="kpi-value" id="kpi-coupon-rev">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Total Discount Given</span><span class="kpi-icon"><i class="bx bx-cut" style="color:#f46a6a;"></i></span></div>
                <div class="kpi-value" id="kpi-discount">—</div></div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="kpi-card"><div class="kpi-header"><span class="kpi-label">Abuse Suspects</span><span class="kpi-icon"><i class="bx bx-shield-x" style="color:#dc3545;"></i></span></div>
                <div class="kpi-value" id="kpi-abuse">—</div><div class="kpi-sub">&gt;3 uses per customer</div></div>
            </div>
        </div>

        {{-- Coupon Table --}}
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title">Coupon Performance</h5>
                        <div class="table-responsive">
                            <table class="table table-nowrap mb-0" id="coupon-table">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th class="text-end">Uses</th>
                                        <th class="text-end">Unique Customers</th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">Discount</th>
                                        <th class="text-end">AOV</th>
                                        <th class="text-end">ROI</th>
                                        <th class="text-end">Uses/Customer</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Abuse Alerts --}}
        <div class="row g-3 mb-4" id="abuse-section" style="display:none;">
            <div class="col-12">
                <div class="card border-danger" data-module="analytics">
                    <div class="card-body">
                        <h5 class="card-title text-danger"><i class="bx bx-shield-x"></i> Potential Coupon Abuse</h5>
                        <p class="text-muted">Coupons with more than 3 average uses per customer may indicate abuse.</p>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0" id="abuse-table">
                                <thead><tr><th>Code</th><th class="text-end">Uses</th><th class="text-end">Customers</th><th class="text-end">Avg Uses</th><th class="text-end">Revenue</th></tr></thead>
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
<script>
const API = '{{ url("/api/v1") }}';
const TOKEN = '{{ session("api_token") ?? "" }}';
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };

async function loadCoupons() {
    try {
        const res = await fetch(`${API}/bi/intel/operations/coupons`, { headers });
        const json = await res.json();
        if (!json.success) throw new Error(json.error);
        const d = json.data;

        $('#kpi-usage-rate').text(d.coupon_usage_rate + '%');
        $('#kpi-usage-sub').text(`${EcomUtils.number(d.total_coupon_orders)} of ${EcomUtils.number(d.total_orders)} orders`);
        $('#kpi-coupon-rev').text('₹' + EcomUtils.number(d.total_coupon_revenue));
        $('#kpi-discount').text('₹' + EcomUtils.number(d.total_discount_given));
        $('#kpi-abuse').text(EcomUtils.number((d.abuse_suspects || []).length));

        let html = '';
        (d.coupons || []).forEach(c => {
            const roiCls = c.roi >= 5 ? 'text-success' : c.roi >= 1 ? 'text-primary' : 'text-danger';
            html += `<tr>
                <td><code>${c.code}</code></td>
                <td class="text-end">${c.uses}</td>
                <td class="text-end">${c.unique_customers}</td>
                <td class="text-end">₹${EcomUtils.number(c.revenue)}</td>
                <td class="text-end text-danger">₹${EcomUtils.number(c.total_discount)}</td>
                <td class="text-end">₹${EcomUtils.number(c.avg_order_value)}</td>
                <td class="text-end ${roiCls}">${c.roi}x</td>
                <td class="text-end">${c.uses_per_customer}</td>
            </tr>`;
        });
        $('#coupon-table tbody').html(html || '<tr><td colspan="8" class="text-center text-muted">No coupon orders found</td></tr>');

        // Abuse
        if (d.abuse_suspects && d.abuse_suspects.length) {
            $('#abuse-section').show();
            let abuseHtml = '';
            d.abuse_suspects.forEach(c => {
                abuseHtml += `<tr><td><code>${c.code}</code></td><td class="text-end">${c.uses}</td><td class="text-end">${c.unique_customers}</td><td class="text-end text-danger fw-bold">${c.uses_per_customer}</td><td class="text-end">₹${EcomUtils.number(c.revenue)}</td></tr>`;
            });
            $('#abuse-table tbody').html(abuseHtml);
        }
    } catch (e) {
        console.error('Coupons:', e);
    }
}

document.addEventListener('DOMContentLoaded', loadCoupons);
</script>
@endpush
