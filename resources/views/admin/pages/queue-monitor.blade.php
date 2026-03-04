@extends('layouts.admin')

@section('title', 'Queue Monitor')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Queue Monitor</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Monitoring</li>
                        <li class="breadcrumb-item active">Queue Monitor</li>
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
                            <p class="text-muted fw-medium">Pending Jobs</p>
                            <h4 class="mb-0" id="pending-count">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-primary align-self-center">
                            <span class="avatar-title"><i class="bx bx-time-five font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Processing</p>
                            <h4 class="mb-0" id="processing-count">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-warning align-self-center">
                            <span class="avatar-title"><i class="bx bx-loader-circle font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Completed (24h)</p>
                            <h4 class="mb-0" id="completed-count">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-success align-self-center">
                            <span class="avatar-title"><i class="bx bx-check-circle font-size-24"></i></span>
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
                            <p class="text-muted fw-medium">Failed (24h)</p>
                            <h4 class="mb-0" id="failed-count">-</h4>
                        </div>
                        <div class="mini-stat-icon avatar-sm rounded-circle bg-danger align-self-center">
                            <span class="avatar-title"><i class="bx bx-error font-size-24"></i></span>
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
                    <h4 class="card-title mb-4">Queue Activity</h4>
                    <p class="text-muted">Queues: <code>default</code>, <code>event-bus</code>, <code>marketing</code>, <code>marketing-flows</code></p>
                    <div class="table-responsive">
                        <table class="table table-centered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Queue</th>
                                    <th>Pending</th>
                                    <th>Processing</th>
                                    <th>Completed</th>
                                    <th>Failed</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="queue-table-body">
                                <tr><td colspan="6" class="text-center text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
