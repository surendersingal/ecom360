@extends('layouts.tenant')

@section('content')

<div class="page-content">
    <div class="container-fluid">

        {{-- Page Title --}}
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Users</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="#">{{ $tenant->name }}</a></li>
                            <li class="breadcrumb-item active">Users</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="mdi mdi-check-circle-outline me-2"></i>
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="mdi mdi-alert-circle-outline me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Main Card --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h4 class="card-title mb-0">Tenant Users</h4>
                        <div class="d-flex gap-2">
                            <a href="{{ route('tenant.users.roles', $tenant->slug) }}"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="mdi mdi-shield-account-outline me-1"></i>
                                Manage Roles
                            </a>
                            <a href="{{ route('tenant.users.create', $tenant->slug) }}"
                               class="btn btn-primary btn-sm">
                                <i class="mdi mdi-account-plus-outline me-1"></i>
                                Add User
                            </a>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-nowrap align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Created</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($users as $user)
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-xs me-3 flex-shrink-0">
                                                        <span class="avatar-title rounded-circle bg-primary bg-soft text-primary font-size-16">
                                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                                        </span>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <span class="fw-medium">{{ $user->name }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-muted">{{ $user->email }}</td>
                                            <td>
                                                @php
                                                    $role = $user->roles->first();
                                                    $roleName = $role ? $role->name : null;
                                                    $badgeClass = match($roleName) {
                                                        'Admin'   => 'badge-soft-danger',
                                                        'Editor'  => 'badge-soft-warning',
                                                        'Viewer'  => 'badge-soft-info',
                                                        null      => 'badge-soft-dark',
                                                        default   => 'badge-soft-secondary',
                                                    };
                                                @endphp
                                                <span class="badge {{ $badgeClass }} font-size-12">
                                                    {{ $roleName ?? 'No Role' }}
                                                </span>
                                            </td>
                                            <td class="text-muted">
                                                {{ $user->created_at->format('M d, Y') }}
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex gap-2 justify-content-end">
                                                    @can('users.manage')
                                                        <a href="{{ route('tenant.users.edit', [$tenant->slug, $user->id]) }}"
                                                           class="btn btn-outline-primary btn-sm"
                                                           title="Edit User">
                                                            <i class="mdi mdi-pencil-outline"></i>
                                                        </a>
                                                        <form action="{{ route('tenant.users.destroy', [$tenant->slug, $user->id]) }}"
                                                              method="POST"
                                                              class="d-inline">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                    class="btn btn-outline-danger btn-sm"
                                                                    title="Delete User"
                                                                    onclick="return confirm('Are you sure you want to remove {{ addslashes($user->name) }} from this tenant? This action cannot be undone.')">
                                                                <i class="mdi mdi-trash-can-outline"></i>
                                                            </button>
                                                        </form>
                                                    @endcan
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                <i class="mdi mdi-account-group-outline font-size-24 d-block mb-2"></i>
                                                No users found for this tenant.
                                                <a href="{{ route('tenant.users.create', $tenant->slug) }}" class="ms-1">Add the first user.</a>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        @if($users->hasPages())
                            <div class="mt-3 d-flex justify-content-end">
                                {{ $users->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection
