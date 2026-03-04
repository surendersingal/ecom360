@extends('layouts.admin')

@section('title', 'Module Manager')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Module Manager</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Modules</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        @forelse($modules as $name => $enabled)
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body text-center">
                    <div class="avatar-md mx-auto mb-4">
                        <span class="avatar-title rounded-circle bg-{{ $enabled ? 'success' : 'secondary' }} bg-soft text-{{ $enabled ? 'success' : 'secondary' }} font-size-24">
                            <i class="bx bx-extension"></i>
                        </span>
                    </div>
                    <h5 class="font-size-15">{{ $name }}</h5>
                    <p class="text-muted mb-0">
                        @if($enabled)
                            <span class="badge bg-success">Enabled</span>
                        @else
                            <span class="badge bg-secondary">Disabled</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>
        @empty
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bx bx-extension text-muted" style="font-size: 48px;"></i>
                    <p class="text-muted mt-3">No modules configured.</p>
                </div>
            </div>
        </div>
        @endforelse
    </div>
@endsection
