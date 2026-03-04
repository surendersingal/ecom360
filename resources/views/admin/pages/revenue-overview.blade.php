@extends('layouts.admin')

@section('title', 'Revenue Overview')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Revenue Overview</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Analytics</li>
                        <li class="breadcrumb-item active">Revenue Overview</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Revenue (30d)</p>
                            <h4 class="mb-0">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center">
                            <span class="avatar-title"><i class="bx bx-dollar-circle font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Avg. Order Value</p>
                            <h4 class="mb-0">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center">
                            <span class="avatar-title"><i class="bx bx-receipt font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Total Orders</p>
                            <h4 class="mb-0">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center">
                            <span class="avatar-title"><i class="bx bx-cart font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Active Tenants</p>
                            <h4 class="mb-0">{{ \App\Models\Tenant::where('is_active', true)->count() }}</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-info align-self-center">
                            <span class="avatar-title"><i class="bx bx-store font-size-24"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Revenue by Tenant</h4>
                    <div id="revenue-chart" style="height: 350px;"></div>
                </div>
            </div>
        </div>
    </div>
@endsection
