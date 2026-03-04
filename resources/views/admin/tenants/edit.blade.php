@extends('layouts.admin')

@section('title', 'Edit Store - ' . $tenant->name)

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Edit Store</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.tenants.index') }}">Stores</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.tenants.show', $tenant) }}">{{ $tenant->name }}</a></li>
                        <li class="breadcrumb-item active">Edit</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Details</h4>
                    <form action="{{ route('admin.tenants.update', $tenant) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Store Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $tenant->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="domain" class="form-label">Domain</label>
                            <input type="text" class="form-control @error('domain') is-invalid @enderror"
                                   id="domain" name="domain" value="{{ old('domain', $tenant->domain) }}">
                            @error('domain')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                               {{ $tenant->is_active ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" id="is_verified" name="is_verified" value="1"
                                               {{ $tenant->is_verified ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_verified">Verified</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary waves-effect waves-light">
                                <i class="bx bx-save me-1"></i> Update Store
                            </button>
                            <a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-secondary waves-effect">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
