@extends('layouts.tenant')

@section('title', 'Voice-to-Cart')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Voice-to-Cart</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Voice-to-Cart</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-primary">
                                <span class="avatar-title bg-soft-primary text-primary rounded-circle fs-3">
                                    <i class="bx bx-microphone"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Voice Commands</p>
                            <h4 class="mb-0">{{ count($results['transcript'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-success">
                                <span class="avatar-title bg-soft-success text-success rounded-circle fs-3">
                                    <i class="bx bx-list-plus"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Items Parsed</p>
                            <h4 class="mb-0">{{ count($results['parsed_items'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-warning">
                                <span class="avatar-title bg-soft-warning text-warning rounded-circle fs-3">
                                    <i class="bx bx-cart"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Cart Conversions</p>
                            <h4 class="mb-0">{{ count($results['cart'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Voice Input</h4>
                </div>
                <div class="card-body">
                    <div class="text-center py-4">
                        <div class="avatar-lg rounded-circle bg-soft-primary mx-auto mb-3" id="voice-btn" style="cursor: pointer;">
                            <span class="avatar-title bg-soft-primary text-primary rounded-circle" style="font-size: 2rem;">
                                <i class="bx bx-microphone"></i>
                            </span>
                        </div>
                        <h5 class="text-muted mb-1">Tap to speak</h5>
                        <p class="text-muted small">Say something like "Add 2 red t-shirts size medium and a pair of blue jeans"</p>
                    </div>

                    @if(!empty($results['transcript']))
                        <div class="border rounded p-3 bg-light mt-3">
                            <label class="form-label fw-semibold mb-2"><i class="bx bx-chat me-1"></i>Transcript</label>
                            @foreach($results['transcript'] as $entry)
                                <div class="d-flex align-items-start mb-2">
                                    <span class="badge bg-primary me-2 mt-1">{{ $loop->iteration }}</span>
                                    <div>
                                        <p class="mb-0">{{ $entry['text'] ?? '' }}</p>
                                        <small class="text-muted">{{ $entry['timestamp'] ?? '' }} — Confidence: {{ number_format(($entry['confidence'] ?? 0) * 100, 0) }}%</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Parsed Items & Cart</h4>
                </div>
                <div class="card-body">
                    <h6 class="text-muted mb-3"><i class="bx bx-analyse me-1"></i>Parsed Items</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Detected Item</th>
                                    <th>Quantity</th>
                                    <th>Attributes</th>
                                    <th>Matched Product</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['parsed_items'] ?? [] as $index => $item)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $item['detected_name'] ?? 'N/A' }}</td>
                                        <td>{{ $item['quantity'] ?? 1 }}</td>
                                        <td>
                                            @foreach($item['attributes'] ?? [] as $key => $val)
                                                <span class="badge bg-soft-info text-info me-1">{{ $key }}: {{ $val }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $item['matched_product'] ?? 'No match' }}</td>
                                        <td>
                                            @if(($item['matched'] ?? false))
                                                <span class="badge bg-success"><i class="bx bx-check me-1"></i>Matched</span>
                                            @else
                                                <span class="badge bg-danger"><i class="bx bx-x me-1"></i>Unmatched</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">
                                            No items parsed yet. Use voice input to get started.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h6 class="text-muted mb-3"><i class="bx bx-cart me-1"></i>Cart Items</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['cart'] ?? [] as $index => $cartItem)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $cartItem['product_name'] ?? 'N/A' }}</td>
                                        <td>{{ $cartItem['quantity'] ?? 1 }}</td>
                                        <td>${{ number_format($cartItem['unit_price'] ?? 0, 2) }}</td>
                                        <td class="fw-semibold">${{ number_format(($cartItem['unit_price'] ?? 0) * ($cartItem['quantity'] ?? 1), 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">
                                            Cart is empty.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if(!empty($results['cart']))
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Total:</td>
                                        <td class="fw-bold text-primary">
                                            ${{ number_format(collect($results['cart'])->sum(fn($i) => ($i['unit_price'] ?? 0) * ($i['quantity'] ?? 1)), 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
