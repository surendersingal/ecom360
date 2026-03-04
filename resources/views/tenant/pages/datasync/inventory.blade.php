@extends('layouts.tenant')

@section('title', 'Data Sync — Inventory')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Synced Inventory</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Inventory</li>
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
                        <h5 class="card-title mb-0">{{ $inventory->total() }} Items</h5>
                        <span class="badge bg-info font-size-12">Auto-synced (Public)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>SKU</th>
                                    <th>Name</th>
                                    <th>Qty</th>
                                    <th>In Stock</th>
                                    <th>Low Stock</th>
                                    <th>Price</th>
                                    <th>Cost</th>
                                    <th>Platform</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($inventory as $item)
                                    <tr class="{{ $item->low_stock ? 'table-warning' : '' }}">
                                        <td><code>{{ $item->sku }}</code></td>
                                        <td>{{ \Illuminate\Support\Str::limit($item->name ?? '—', 30) }}</td>
                                        <td class="fw-bold">{{ number_format($item->qty ?? 0) }}</td>
                                        <td><span class="badge bg-{{ $item->is_in_stock ? 'success' : 'danger' }}">{{ $item->is_in_stock ? 'Yes' : 'No' }}</span></td>
                                        <td>
                                            @if($item->low_stock)
                                                <span class="badge bg-warning"><i class="bx bx-error"></i> Low</span>
                                            @else
                                                <span class="text-muted">OK</span>
                                            @endif
                                        </td>
                                        <td>{{ number_format($item->price ?? 0, 2) }}</td>
                                        <td>{{ $item->cost ? number_format($item->cost, 2) : '—' }}</td>
                                        <td><span class="badge bg-primary">{{ $item->platform }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">No inventory synced yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $inventory->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
