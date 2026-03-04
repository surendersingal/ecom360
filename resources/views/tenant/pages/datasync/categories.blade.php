@extends('layouts.tenant')

@section('title', 'Data Sync — Categories')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Synced Categories</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Categories</li>
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
                        <h5 class="card-title mb-0">{{ $categories->total() }} Categories</h5>
                        <span class="badge bg-info font-size-12">Auto-synced (Public)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Level</th>
                                    <th>Parent ID</th>
                                    <th>Products</th>
                                    <th>Active</th>
                                    <th>Platform</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($categories as $cat)
                                    <tr>
                                        <td style="padding-left: {{ ($cat->level ?? 0) * 20 }}px">
                                            {{ $cat->name }}
                                            @if($cat->url_key) <small class="text-muted">/{{ $cat->url_key }}</small> @endif
                                        </td>
                                        <td>{{ $cat->level ?? 0 }}</td>
                                        <td>{{ $cat->parent_id ?? '—' }}</td>
                                        <td>{{ $cat->product_count ?? 0 }}</td>
                                        <td><span class="badge bg-{{ $cat->is_active ? 'success' : 'secondary' }}">{{ $cat->is_active ? 'Yes' : 'No' }}</span></td>
                                        <td><span class="badge bg-primary">{{ $cat->platform }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No categories synced yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $categories->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
