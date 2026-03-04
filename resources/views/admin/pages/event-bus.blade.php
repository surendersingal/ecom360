@extends('layouts.admin')

@section('title', 'Event Bus')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Event Bus</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Monitoring</li>
                        <li class="breadcrumb-item active">Event Bus</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Event Routes</h4>
                    <p class="text-muted mb-3">Cross-module event routing managed by <code>EventBusRouter</code>.</p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Event</th>
                                    <th>Source</th>
                                    <th>Target</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td><code>analytics::report.generated</code></td><td>Analytics</td><td>Marketing</td></tr>
                                <tr><td><code>analytics::rfm_segment_changed</code></td><td>Analytics</td><td>Marketing</td></tr>
                                <tr><td><code>analytics::audience_segment_entered</code></td><td>Analytics</td><td>Marketing</td></tr>
                                <tr><td><code>analytics::audience_segment_exited</code></td><td>Analytics</td><td>Marketing</td></tr>
                                <tr><td><code>analytics::behavioral_trigger</code></td><td>Analytics</td><td>Marketing</td></tr>
                                <tr><td><code>analytics::realtime_alert</code></td><td>Analytics</td><td>Notification</td></tr>
                                <tr><td><code>AiSearch::search.completed</code></td><td>AiSearch</td><td>Analytics</td></tr>
                                <tr><td><code>Chatbot::intent.captured</code></td><td>Chatbot</td><td>BI</td></tr>
                                <tr><td><code>BusinessIntelligence::alert_triggered</code></td><td>BI</td><td>Marketing</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Recent Events</h4>
                    <p class="text-muted">Latest IntegrationEvent dispatches across the platform.</p>
                    <div id="recent-events-container">
                        <div class="text-center py-4 text-muted">
                            <i class="bx bx-transfer-alt font-size-24 d-block mb-2"></i>
                            Event stream will appear here in real-time.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
