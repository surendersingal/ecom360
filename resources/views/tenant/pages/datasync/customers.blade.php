@extends('layouts.tenant')

@section('title', 'Data Sync — Customers')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Synced Customers</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Data Sync</li>
                        <li class="breadcrumb-item active">Customers</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">{{ $customers->total() }} Customers</h5>
                        <span class="badge bg-danger font-size-12">Sensitive — Full PII Consent Required</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Email</th>
                                    <th>Name</th>
                                    <th>External ID</th>
                                    <th>Platform</th>
                                    <th>Synced</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($customers as $cust)
                                    <tr>
                                        <td>{{ $cust->email }}</td>
                                        <td>{{ $cust->name ?: (($cust->firstname ?? '') . ' ' . ($cust->lastname ?? '')) }}</td>
                                        <td><code>{{ $cust->external_id }}</code></td>
                                        <td><span class="badge bg-primary">{{ $cust->platform }}</span></td>
                                        <td>{{ $cust->synced_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">No customers synced yet. Ensure customer sync is enabled with PII consent in your store module.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $customers->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
