@extends('layouts.tenant')

@section('title', 'Store Settings')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Store Settings</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Settings</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Information</h4>
                    <table class="table table-nowrap mb-0">
                        <tr><th>Name:</th><td>{{ $tenant->name }}</td></tr>
                        <tr><th>Slug:</th><td><code>{{ $tenant->slug }}</code></td></tr>
                        <tr><th>Domain:</th><td>{{ $tenant->domain ?? 'Not set' }}</td></tr>
                        <tr><th>API Key:</th><td><code class="user-select-all">{{ $tenant->api_key }}</code></td></tr>
                        <tr><th>Status:</th><td><span class="badge bg-{{ $tenant->is_active ? 'success' : 'danger' }}">{{ $tenant->is_active ? 'Active' : 'Inactive' }}</span></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Tracking Configuration</h4>
                    <div class="mb-3"><div class="form-check form-switch form-switch-lg"><input class="form-check-input" type="checkbox" checked disabled><label class="form-check-label">Track Page Views</label></div></div>
                    <div class="mb-3"><div class="form-check form-switch form-switch-lg"><input class="form-check-input" type="checkbox" checked disabled><label class="form-check-label">Track E-commerce Events</label></div></div>
                    <div class="mb-3"><div class="form-check form-switch form-switch-lg"><input class="form-check-input" type="checkbox" checked disabled><label class="form-check-label">Track Custom Events</label></div></div>
                    <div class="mb-3"><div class="form-check form-switch form-switch-lg"><input class="form-check-input" type="checkbox" disabled><label class="form-check-label">Enable GeoIP Tracking</label></div></div>
                </div>
            </div>
        </div>
    </div>
@endsection