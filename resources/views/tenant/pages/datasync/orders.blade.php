@extends('layouts.tenant')

@section('title', 'Data Sync — Orders')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Synced Orders</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Orders</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">{{ $orders->total() }} Orders</h5>
                        <span class="badge bg-warning font-size-12">Restricted — Requires Consent</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th>Items</th>
                                    <th>Customer</th>
                                    <th>Platform</th>
                                    <th>Synced</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($orders as $order)
                                    <tr>
                                        <td><code>{{ $order->order_number ?: $order->external_id }}</code></td>
                                        <td>
                                            @php $s = $order->status; @endphp
                                            <span class="badge bg-{{ $s === 'complete' ? 'success' : ($s === 'pending' ? 'warning' : ($s === 'canceled' ? 'danger' : 'info')) }}">{{ $s }}</span>
                                        </td>
                                        <td>{{ $order->currency ?? 'USD' }} {{ number_format($order->grand_total ?? 0, 2) }}</td>
                                        <td>{{ $order->total_qty ?? count($order->items ?? []) }}</td>
                                        <td>{{ $order->customer_email ?? '—' }}</td>
                                        <td><span class="badge bg-primary">{{ $order->platform }}</span></td>
                                        <td>{{ $order->synced_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No orders synced yet. Ensure order sync is enabled in your store module.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $orders->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
