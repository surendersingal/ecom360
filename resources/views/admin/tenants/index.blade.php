@extends('layouts.admin')

@section('title', 'Stores')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Stores</h4>
                <div class="page-title-right">
                    <a href="{{ route('admin.tenants.create') }}" class="btn btn-primary waves-effect waves-light">
                        <i class="bx bx-plus me-1"></i> New Store
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tenants-table" class="table table-bordered dt-responsive nowrap w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Domain</th>
                                    <th>Users</th>
                                    <th>Status</th>
                                    <th>Verified</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tenants as $tenant)
                                <tr>
                                    <td>{{ $tenant->id }}</td>
                                    <td>
                                        <a href="{{ route('admin.tenants.show', $tenant) }}" class="text-body fw-bold">
                                            {{ $tenant->name }}
                                        </a>
                                    </td>
                                    <td><code>{{ $tenant->slug }}</code></td>
                                    <td>{{ $tenant->domain ?? '-' }}</td>
                                    <td>{{ $tenant->users_count }}</td>
                                    <td>
                                        @if($tenant->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-danger">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($tenant->is_verified)
                                            <span class="badge bg-info">Verified</span>
                                        @else
                                            <span class="badge bg-secondary">Unverified</span>
                                        @endif
                                    </td>
                                    <td>{{ $tenant->created_at->format('M d, Y') }}</td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-sm btn-info" title="View">
                                                <i class="bx bx-show"></i>
                                            </a>
                                            <a href="{{ route('admin.tenants.edit', $tenant) }}" class="btn btn-sm btn-warning" title="Edit">
                                                <i class="bx bx-edit"></i>
                                            </a>
                                            <a href="{{ route('admin.impersonate.start', $tenant) }}" class="btn btn-sm btn-primary" title="Login as Tenant">
                                                <i class="bx bx-log-in-circle"></i>
                                            </a>
                                            <form action="{{ route('admin.tenants.toggle-active', $tenant) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm {{ $tenant->is_active ? 'btn-danger' : 'btn-success' }}" title="{{ $tenant->is_active ? 'Deactivate' : 'Activate' }}">
                                                    <i class="bx {{ $tenant->is_active ? 'bx-pause' : 'bx-play' }}"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
