@extends('layouts.admin')

@section('title', 'Create Store')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Create Store</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.tenants.index') }}">Stores</a></li>
                        <li class="breadcrumb-item active">Create</li>
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
                    <form action="{{ route('admin.tenants.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label">Store Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name') }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="domain" class="form-label">Domain</label>
                            <input type="text" class="form-control @error('domain') is-invalid @enderror"
                                   id="domain" name="domain" value="{{ old('domain') }}" placeholder="e.g. store.example.com">
                            @error('domain')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch form-switch-lg">
                                        <input class="form-check-input" type="checkbox" id="is_verified" name="is_verified" value="1">
                                        <label class="form-check-label" for="is_verified">Verified</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h5 class="mb-3">Default User (Optional)</h5>

                        <div class="mb-3">
                            <label for="user_name" class="form-label">User Name</label>
                            <input type="text" class="form-control" id="user_name" name="user_name" value="{{ old('user_name') }}">
                        </div>

                        <div class="mb-3">
                            <label for="user_email" class="form-label">User Email</label>
                            <input type="email" class="form-control" id="user_email" name="user_email" value="{{ old('user_email') }}">
                        </div>

                        <div class="mb-3">
                            <label for="user_password" class="form-label">User Password</label>
                            <input type="password" class="form-control" id="user_password" name="user_password" placeholder="Default: password">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary waves-effect waves-light">
                                <i class="bx bx-save me-1"></i> Create Store
                            </button>
                            <a href="{{ route('admin.tenants.index') }}" class="btn btn-secondary waves-effect">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
