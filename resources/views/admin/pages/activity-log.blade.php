@extends('layouts.admin')

@section('title', 'Activity Log')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Activity Log</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Activity Log</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Recent Activity</h4>
                    <div class="text-center py-5">
                        <i class="bx bx-list-ul text-muted" style="font-size: 48px;"></i>
                        <p class="text-muted mt-3">Activity logging will be available once the platform has more data.</p>
                        <p class="text-muted">Events from the tracking SDK, user actions, and system events will appear here.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
