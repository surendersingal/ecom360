@extends('layouts.admin')

@section('title', $tenant->name)

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">{{ $tenant->name }}</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.tenants.index') }}">Stores</a></li>
                        <li class="breadcrumb-item active">{{ $tenant->name }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Store Info -->
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Info</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <tbody>
                                <tr>
                                    <th scope="row">Name:</th>
                                    <td>{{ $tenant->name }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Slug:</th>
                                    <td><code>{{ $tenant->slug }}</code></td>
                                </tr>
                                <tr>
                                    <th scope="row">Domain:</th>
                                    <td>{{ $tenant->domain ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">API Key:</th>
                                    <td>
                                        <code class="user-select-all">{{ $tenant->api_key }}</code>
                                        <form action="{{ route('admin.tenants.regenerate-key', $tenant) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning ms-2" title="Regenerate">
                                                <i class="bx bx-refresh"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Status:</th>
                                    <td>
                                        @if($tenant->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-danger">Inactive</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Verified:</th>
                                    <td>
                                        @if($tenant->is_verified)
                                            <span class="badge bg-info">Verified</span>
                                        @else
                                            <span class="badge bg-secondary">Unverified</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Created:</th>
                                    <td>{{ $tenant->created_at->format('M d, Y H:i') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <a href="{{ route('admin.tenants.edit', $tenant) }}" class="btn btn-warning btn-sm">
                            <i class="bx bx-edit me-1"></i> Edit
                        </a>
                        <a href="{{ route('admin.impersonate.start', $tenant) }}" class="btn btn-primary btn-sm">
                            <i class="bx bx-log-in-circle me-1"></i> Login as Tenant
                        </a>
                        <form action="{{ route('admin.tenants.toggle-active', $tenant) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $tenant->is_active ? 'btn-danger' : 'btn-success' }}">
                                <i class="bx {{ $tenant->is_active ? 'bx-pause' : 'bx-play' }} me-1"></i>
                                {{ $tenant->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        <form action="{{ route('admin.tenants.verify', $tenant) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $tenant->is_verified ? 'btn-secondary' : 'btn-info' }}">
                                <i class="bx bx-badge-check me-1"></i>
                                {{ $tenant->is_verified ? 'Unverify' : 'Verify' }}
                            </button>
                        </form>
                        <form action="{{ route('admin.tenants.destroy', $tenant) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Are you sure you want to delete this store? This action cannot be undone.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bx bx-trash me-1"></i> Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Users ({{ $users->count() }})</h4>
                    <div class="table-responsive">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($users as $user)
                                <tr>
                                    <td>{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>{{ $user->created_at->format('M d, Y') }}</td>
                                    <td>
                                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-warning">
                                            <i class="bx bx-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No users for this store</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Integration Snippet -->
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Integration Snippet</h4>
                    <p class="text-muted">Add this to the store's website to start collecting analytics:</p>
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
        </div>
    </div>
@endsection
