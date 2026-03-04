@extends('layouts.tenant')

@section('title', 'Data Sync — Products')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Synced Products</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Products</li>
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
                        <h5 class="card-title mb-0">{{ $products->total() }} Products</h5>
                        <span class="badge bg-info font-size-12">Auto-synced (Public)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>SKU</th>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Platform</th>
                                    <th>Synced</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($products as $p)
                                    <tr>
                                        <td><code>{{ $p->sku ?: '—' }}</code></td>
                                        <td>{{ \Illuminate\Support\Str::limit($p->name, 40) }}</td>
                                        <td>
                                            {{ number_format($p->price ?? 0, 2) }}
                                            @if($p->special_price)
                                                <small class="text-danger ms-1">{{ number_format($p->special_price, 2) }}</small>
                                            @endif
                                        </td>
                                        <td><span class="badge bg-{{ $p->status === 'enabled' || $p->status === 'publish' ? 'success' : 'secondary' }}">{{ $p->status }}</span></td>
                                        <td><span class="badge bg-primary">{{ $p->platform }}</span></td>
                                        <td>{{ $p->synced_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No products synced yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $products->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
