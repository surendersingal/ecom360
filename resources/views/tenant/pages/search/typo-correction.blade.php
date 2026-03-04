@extends('layouts.tenant')

@section('title', 'Typo & Phonetic Auto-Correction')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Typo & Phonetic Auto-Correction</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI Search</li>
                        <li class="breadcrumb-item active">Typo & Phonetic Auto-Correction</li>
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
                                    <i class="bx bx-edit"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Corrections Made</p>
                            <h4 class="mb-0">{{ count($results['suggestions'] ?? []) }}</h4>
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
                                    <i class="bx bx-revision"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Search Recovery Rate</p>
                            <h4 class="mb-0">
                                @if(count($results['suggestions'] ?? []) > 0 && count($results['products'] ?? []) > 0)
                                    {{ number_format((count($results['products']) / count($results['suggestions'])) * 100, 1) }}%
                                @else
                                    0%
                                @endif
                            </h4>
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
                                    <i class="bx bx-shield-quarter"></i>
                                </span>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="text-muted mb-1">Zero-Result Prevented</p>
                            <h4 class="mb-0">{{ count($results['products'] ?? []) }}</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">Recent Corrections</h4>
                    @if(!empty($results['original']))
                        <div>
                            <span class="badge bg-soft-danger text-danger me-1">Original: {{ $results['original'] }}</span>
                            <i class="bx bx-right-arrow-alt text-muted"></i>
                            <span class="badge bg-soft-success text-success ms-1">Corrected: {{ $results['corrected'] ?? $results['original'] }}</span>
                        </div>
                    @endif
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Original Query</th>
                                    <th></th>
                                    <th>Corrected Query</th>
                                    <th>Correction Type</th>
                                    <th>Products Found</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($results['suggestions'] ?? [] as $index => $suggestion)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td><code class="text-danger">{{ $suggestion['original'] ?? 'N/A' }}</code></td>
                                        <td class="text-center"><i class="bx bx-right-arrow-alt text-muted fs-4"></i></td>
                                        <td><code class="text-success">{{ $suggestion['corrected'] ?? 'N/A' }}</code></td>
                                        <td>
                                            <span class="badge bg-soft-info text-info">{{ $suggestion['type'] ?? 'typo' }}</span>
                                        </td>
                                        <td>{{ $suggestion['product_count'] ?? 0 }}</td>
                                        <td>{{ $suggestion['timestamp'] ?? 'N/A' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bx bx-edit fs-1 d-block mb-2"></i>
                                            No typo corrections recorded yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
