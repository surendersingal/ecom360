@extends('layouts.admin')

@section('title', 'System Health')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">System Health</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">System Health</li>
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
                            <p class="text-muted fw-medium">PHP Version</p>
                            <h5 class="mb-0">{{ $health['php_version'] }}</h5>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-primary mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-primary">
                                    <i class="bx bxl-php font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Laravel</p>
                            <h5 class="mb-0">{{ $health['laravel'] }}</h5>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-danger mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-danger">
                                    <i class="bx bxl-tailwind-css font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Memory Usage</p>
                            <h5 class="mb-0">{{ $health['memory_usage'] }}</h5>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-warning mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-warning">
                                    <i class="bx bx-chip font-size-24"></i>
                                </span>
                            </div>
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
                            <p class="text-muted fw-medium">Disk Free</p>
                            <h5 class="mb-0">{{ $health['disk_free'] }}</h5>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-success mini-stat-icon">
                                <span class="avatar-title rounded-circle bg-success">
                                    <i class="bx bx-hdd font-size-24"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Services Status</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <tbody>
                                <tr>
                                    <td><i class="bx bx-check-circle text-success me-2"></i> MySQL</td>
                                    <td><span class="badge bg-success">Connected</span></td>
                                </tr>
                                <tr>
                                    <td><i class="bx bx-check-circle text-success me-2"></i> MongoDB</td>
                                    <td><span class="badge bg-success">Connected</span></td>
                                </tr>
                                <tr>
                                    <td><i class="bx bx-check-circle text-success me-2"></i> Redis</td>
                                    <td><span class="badge bg-success">Connected</span></td>
                                </tr>
                                <tr>
                                    <td><i class="bx bx-check-circle text-success me-2"></i> Queue Worker</td>
                                    <td><span class="badge bg-warning">Check Manually</span></td>
                                </tr>
                                <tr>
                                    <td><i class="bx bx-check-circle text-success me-2"></i> WebSocket (Reverb)</td>
                                    <td><span class="badge bg-warning">Check Manually</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Environment</h4>
                    <div class="table-responsive">
                        <table class="table table-nowrap mb-0">
                            <tbody>
                                <tr>
                                    <td>App Environment</td>
                                    <td><code>{{ app()->environment() }}</code></td>
                                </tr>
                                <tr>
                                    <td>Debug Mode</td>
                                    <td>
                                        @if(config('app.debug'))
                                            <span class="badge bg-warning">Enabled</span>
                                        @else
                                            <span class="badge bg-success">Disabled</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td>Cache Driver</td>
                                    <td><code>{{ config('cache.default') }}</code></td>
                                </tr>
                                <tr>
                                    <td>Session Driver</td>
                                    <td><code>{{ config('session.driver') }}</code></td>
                                </tr>
                                <tr>
                                    <td>Queue Driver</td>
                                    <td><code>{{ config('queue.default') }}</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
