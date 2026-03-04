@extends('layouts.admin')

@section('title', 'Tenant Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Tenant Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Tenant Analytics</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Store Comparison</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered dt-responsive nowrap w-100">
                            <thead>
                                <tr>
                                    <th>Store</th>
                                    <th>Users</th>
                                    <th class="text-end">Events (30d)</th>
                                    <th class="text-end">Sessions (30d)</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tenants as $tenant)
                                @php $a = $analytics[$tenant->id] ?? []; @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.tenants.show', $tenant) }}" class="fw-bold">
                                            {{ $tenant->name }}
                                        </a>
                                    </td>
                                    <td>{{ $tenant->users_count }}</td>
                                    <td class="text-end">{{ number_format($a['total_events'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($a['unique_sessions'] ?? 0) }}</td>
                                    <td>
                                        <span class="badge bg-{{ $tenant->is_active ? 'success' : 'danger' }}">
                                            {{ $tenant->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>{{ $tenant->created_at->format('M d, Y') }}</td>
                                    <td>
                                        <a href="{{ route('admin.impersonate.start', $tenant) }}" class="btn btn-sm btn-primary">
                                            <i class="bx bx-log-in-circle me-1"></i> View Analytics
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
