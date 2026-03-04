@extends('layouts.admin')

@section('title', 'Roles & Permissions')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Roles &amp; Permissions</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Manage</li>
                        <li class="breadcrumb-item active">Roles &amp; Permissions</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Roles</h4>
                        <button type="button" class="btn btn-primary btn-sm"><i class="bx bx-plus me-1"></i> New Role</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-centered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Role</th>
                                    <th>Guard</th>
                                    <th>Permissions</th>
                                    <th>Users</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $roles = \Spatie\Permission\Models\Role::withCount('permissions', 'users')->get();
                                @endphp
                                @forelse($roles as $role)
                                <tr>
                                    <td><span class="badge bg-soft-primary text-primary font-size-12">{{ $role->name }}</span></td>
                                    <td>{{ $role->guard_name }}</td>
                                    <td>{{ $role->permissions_count }}</td>
                                    <td>{{ $role->users_count }}</td>
                                    <td>
                                        <a href="javascript:void(0)" class="text-primary me-2" title="Edit"><i class="bx bx-pencil font-size-18"></i></a>
                                        <a href="javascript:void(0)" class="text-danger" title="Delete"><i class="bx bx-trash font-size-18"></i></a>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted">No roles defined yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
