@extends('layouts.tenant')

@section('title', 'Shop the Room Visual Search')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Shop the Room Visual Search</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Shop the Room Visual Search</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-primary">
                                <span class="avatar-title bg-soft-primary text-primary rounded-circle fs-3">
                                    <i class="bx bx-image"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Scene Type</p>
                            <h4 class="mb-0">{{ $results['scene_type'] ?? 'N/A' }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-success">
                                <span class="avatar-title bg-soft-success text-success rounded-circle fs-3">
                                    <i class="bx bx-search-alt"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Objects Detected</p>
                            <h4 class="mb-0">{{ count($results['detected_objects'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6">
            <div class="card card-h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="avatar-md rounded-circle bg-soft-warning">
                                <span class="avatar-title bg-soft-warning text-warning rounded-circle fs-3">
                                    <i class="bx bx-package"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Products Matched</p>
                            <h4 class="mb-0">{{ count($results['products'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Upload Room Image</h4>
                </div>
                <div class="card-body">
                    <div class="border border-dashed rounded p-4 text-center" id="image-upload-area">
                        <i class="bx bx-cloud-upload fs-1 text-muted d-block mb-2"></i>
                        <h5 class="text-muted">Drag & Drop an image here</h5>
                        <p class="text-muted mb-3">or click to browse files</p>
                        <input type="file" class="form-control" accept="image/*" id="room-image-input">
                    </div>
                    <div class="mt-3" id="image-preview" style="display: none;">
                        <img src="" alt="Uploaded Room" class="img-fluid rounded" id="preview-img">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">Detected Objects & Matched Products</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        @forelse($results['products'] ?? [] as $product)
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card border shadow-none mb-0">
                                    <div class="card-body p-3">
                                        <div class="bg-light rounded text-center py-3 mb-2">
                                            <i class="bx bx-cube fs-1 text-muted"></i>
                                        </div>
                                        <h6 class="mb-1 text-truncate">{{ $product['name'] ?? 'N/A' }}</h6>
                                        <p class="text-muted small mb-1">{{ $product['category'] ?? 'N/A' }}</p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-semibold text-primary">${{ number_format($product['price'] ?? 0, 2) }}</span>
                                            <span class="badge bg-soft-success text-success">{{ number_format(($product['confidence'] ?? 0) * 100, 0) }}% match</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="col-12 text-center text-muted py-4">
                                <i class="bx bx-image fs-1 d-block mb-2"></i>
                                Upload a room image to detect products.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
