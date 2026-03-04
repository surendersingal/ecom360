@extends('layouts.admin')

@section('title', 'Platform Settings')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Platform Settings</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
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
                    <h4 class="card-title mb-4">General Settings</h4>
                    <form>
                        <div class="mb-3">
                            <label class="form-label">Platform Name</label>
                            <input type="text" class="form-control" value="Ecom360" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Default Timezone</label>
                            <select class="form-select" disabled>
                                <option>UTC</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch form-switch-lg">
                                <input class="form-check-input" type="checkbox" checked disabled>
                                <label class="form-check-label">Allow New Registrations</label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary waves-effect waves-light" disabled>
                            <i class="bx bx-save me-1"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">API Configuration</h4>
                    <form>
                        <div class="mb-3">
                            <label class="form-label">Rate Limit (requests/minute)</label>
                            <input type="number" class="form-control" value="60" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Batch Size Limit</label>
                            <input type="number" class="form-control" value="100" disabled>
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch form-switch-lg">
                                <input class="form-check-input" type="checkbox" checked disabled>
                                <label class="form-check-label">Enable SDK Collection</label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary waves-effect waves-light" disabled>
                            <i class="bx bx-save me-1"></i> Save API Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
