@extends('layouts.tenant')

@section('title', 'Integration Guide')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Integration Guide</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Integration</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Step 1: Add the Tracking Script</h4>
                    <p class="text-muted">Copy and paste this into your website's <code>&lt;head&gt;</code> tag:</p>
                    <pre class="bg-light p-3 rounded"><code>&lt;script&gt;
  !function(e,c,o,m){e.ecom360=e.ecom360||function(){
  (e.ecom360.q=e.ecom360.q||[]).push(arguments)};
  var s=c.createElement('script');s.async=1;
  s.src='{{ url('/') }}/js/ecom360-sdk.js';
  c.getElementsByTagName('head')[0].appendChild(s);
  }(window,document);
  ecom360('init','{{ $tenant->api_key }}');
  ecom360('track','pageview');
&lt;/script&gt;</code></pre>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Step 2: Track E-commerce Events</h4>
                    <pre class="bg-light p-3 rounded"><code>// Product View
ecom360('track', 'product_view', { product_id: 'SKU-123', name: 'Product', price: 29.99 });

// Add to Cart
ecom360('track', 'add_to_cart', { product_id: 'SKU-123', quantity: 1, price: 29.99 });

// Purchase
ecom360('track', 'purchase', { order_id: 'ORD-456', total: 59.98 });</code></pre>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Your API Key</h4>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" value="{{ $tenant->api_key }}" id="api-key-input" readonly>
                        <button class="btn btn-outline-primary" onclick="navigator.clipboard.writeText(document.getElementById('api-key-input').value);">
                            <i class="bx bx-copy"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">API Endpoints</h4>
                    <table class="table table-nowrap table-sm mb-0">
                        <tr><td><code>POST</code></td><td><small>/api/v1/collect</small></td></tr>
                        <tr><td><code>POST</code></td><td><small>/api/v1/collect/batch</small></td></tr>
                    </table>
                </div>
            </div>
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="text-white mb-3">Need Help?</h5>
                    <p class="text-white-50">Check the documentation for integration assistance.</p>
                </div>
            </div>
        </div>
    </div>
@endsection