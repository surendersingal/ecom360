@extends('layouts.tenant')

@section('title', 'Audience Segments')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Audience Segments</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Segments</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    @php
    $segmentGroups = [
        ['title' => 'Visitor Segments', 'data' => $visitors, 'id' => 'visitor'],
        ['title' => 'Customer Segments (RFM)', 'data' => $customers, 'id' => 'customer'],
        ['title' => 'Traffic Segments', 'data' => $traffic, 'id' => 'traffic'],
    ];
    @endphp

    @foreach($segmentGroups as $group)
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">{{ $group['title'] }}</h4>
                    <div class="row">
                        @forelse($group['data'] as $seg)
                        <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">{{ $seg['name'] ?? '-' }}</h6>
                                    <span class="badge bg-{{ $seg['color'] ?? 'primary' }} rounded-pill">{{ number_format($seg['count'] ?? 0) }}</span>
                                </div>
                                <p class="text-muted mb-0 small">{{ $seg['description'] ?? '' }}</p>
                            </div>
                        </div>
                        @empty
                        <div class="col-12"><p class="text-center text-muted">No segment data yet</p></div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
@endsection