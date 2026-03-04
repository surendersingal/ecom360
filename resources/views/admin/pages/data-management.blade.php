@extends('layouts.admin')

@section('title', 'Data Management')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Data Management</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Data Management</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Cache Management</h4>
                    <p class="text-muted">Clear various application caches to resolve issues or free resources.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary waves-effect" onclick="alert('Cache cleared (demo)')">
                            <i class="bx bx-trash me-1"></i> Clear App Cache
                        </button>
                        <button class="btn btn-outline-warning waves-effect" onclick="alert('View cache cleared (demo)')">
                            <i class="bx bx-file me-1"></i> Clear View Cache
                        </button>
                        <button class="btn btn-outline-danger waves-effect" onclick="alert('Config cache cleared (demo)')">
                            <i class="bx bx-cog me-1"></i> Clear Config Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Data Retention</h4>
                    <p class="text-muted">Configure how long analytics data is retained before automatic cleanup.</p>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <tbody>
                                <tr>
                                    <td>Tracking Events</td>
                                    <td><span class="badge bg-info">90 days</span></td>
                                </tr>
                                <tr>
                                    <td>Session Data</td>
                                    <td><span class="badge bg-info">60 days</span></td>
                                </tr>
                                <tr>
                                    <td>Aggregated Reports</td>
                                    <td><span class="badge bg-info">365 days</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
