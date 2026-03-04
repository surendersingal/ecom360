@extends('layouts.tenant')

@section('title', 'Smart Recommendations')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Smart Recommendations</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI &amp; Insights</li>
                        <li class="breadcrumb-item active">Recommendations</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Lookup Forms --}}
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bx bx-user me-1 text-primary"></i> For a Visitor</h5>
                    <form id="visitor-rec-form" class="d-flex gap-2">
                        <input type="text" class="form-control" name="visitor_id" placeholder="Enter visitor ID" required>
                        <input type="number" class="form-control" name="limit" value="10" min="1" max="50" style="width:80px">
                        <button type="submit" class="btn btn-primary flex-shrink-0"><i class="bx bx-search"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bx bx-package me-1 text-info"></i> For a Product</h5>
                    <form id="product-rec-form" class="d-flex gap-2">
                        <input type="text" class="form-control" name="product_id" placeholder="Enter product ID" required>
                        <input type="number" class="form-control" name="limit" value="8" min="1" max="50" style="width:80px">
                        <button type="submit" class="btn btn-info flex-shrink-0"><i class="bx bx-search"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Trending Products --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0"><i class="bx bx-trending-up text-warning me-1"></i> Trending Products</h4>
                        <button class="btn btn-sm btn-soft-primary" id="btn-refresh-trending"><i class="bx bx-refresh"></i></button>
                    </div>
                    <div id="trending-container">
                        <div class="text-center py-4"><i class="bx bx-loader-alt bx-spin font-size-24"></i><p class="text-muted mt-2">Loading trending products...</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Results --}}
    <div class="row" id="rec-results" style="display:none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0" id="rec-results-title">Recommendations</h4>
                        <button class="btn btn-sm btn-soft-secondary" id="btn-close-results"><i class="bx bx-x"></i> Close</button>
                    </div>
                    <div id="rec-results-container"></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API = '/analytics/advanced/recommendations';

    function renderProducts(items, container) {
        const $c = $(container).empty();
        if (!items || !items.length) {
            $c.html('<div class="text-center text-muted py-4">No recommendations available.</div>');
            return;
        }
        let html = '<div class="row">';
        items.forEach((p, i) => {
            const name = p.name || p.product_name || p.title || `Product ${p.product_id || i+1}`;
            const score = p.score || p.relevance || p.confidence || null;
            const price = p.price || p.avg_price || null;
            const reason = p.reason || p.type || p.algorithm || '';
            html += `<div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                <div class="card border shadow-none mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-start">
                            <div class="avatar-sm me-2 flex-shrink-0">
                                <span class="avatar-title rounded bg-soft-primary text-primary font-size-16"><i class="bx bx-package"></i></span>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="mb-1 text-truncate">${name}</h6>
                                ${price !== null ? `<p class="text-success fw-medium mb-1">$${EcomUtils.number(price)}</p>` : ''}
                                ${score !== null ? `<small class="text-muted">Score: ${typeof score === 'number' ? score.toFixed(2) : score}</small>` : ''}
                                ${reason ? `<br><span class="badge bg-soft-info text-info font-size-11">${reason}</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        });
        html += '</div>';
        $c.html(html);
    }

    function loadTrending() {
        $('#trending-container').html('<div class="text-center py-4"><i class="bx bx-loader-alt bx-spin font-size-24"></i></div>');
        EcomAPI.get(API + '?limit=12').then(json => {
            const items = json.data?.recommendations || json.data?.products || json.data || [];
            renderProducts(Array.isArray(items) ? items : [], '#trending-container');
        }).catch(err => {
            $('#trending-container').html(`<div class="text-center text-danger py-4">${err.message || 'Failed to load'}</div>`);
        });
    }

    loadTrending();
    $('#btn-refresh-trending').on('click', loadTrending);

    // Visitor recommendations
    $('#visitor-rec-form').on('submit', function(e) {
        e.preventDefault();
        const data = EcomCRUD.formData('visitor-rec-form');
        EcomAPI.get(`${API}?visitor_id=${encodeURIComponent(data.visitor_id)}&limit=${data.limit}`).then(json => {
            const items = json.data?.recommendations || json.data?.products || json.data || [];
            $('#rec-results-title').text('Recommendations for Visitor: ' + data.visitor_id);
            renderProducts(Array.isArray(items) ? items : [], '#rec-results-container');
            $('#rec-results').slideDown();
        }).catch(err => toastr.error(err.message));
    });

    // Product recommendations
    $('#product-rec-form').on('submit', function(e) {
        e.preventDefault();
        const data = EcomCRUD.formData('product-rec-form');
        EcomAPI.get(`${API}?product_id=${encodeURIComponent(data.product_id)}&limit=${data.limit}`).then(json => {
            const items = json.data?.recommendations || json.data?.products || json.data || [];
            $('#rec-results-title').text('Similar to Product: ' + data.product_id);
            renderProducts(Array.isArray(items) ? items : [], '#rec-results-container');
            $('#rec-results').slideDown();
        }).catch(err => toastr.error(err.message));
    });

    $('#btn-close-results').on('click', () => $('#rec-results').slideUp());
});
</script>
@endsection
