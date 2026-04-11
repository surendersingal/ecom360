@extends('layouts.tenant')

@section('content')

<div class="page-content">
    <div class="container-fluid">

        {{-- Page Title --}}
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Manage Roles</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="#">{{ $tenant->name }}</a></li>
                            <li class="breadcrumb-item">
                                <a href="{{ route('tenant.users.index', $tenant->slug) }}">Users</a>
                            </li>
                            <li class="breadcrumb-item active">Roles</li>
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

        <div class="row g-4">

            {{-- ============================================================ --}}
            {{-- LEFT PANEL — Existing Roles                                  --}}
            {{-- ============================================================ --}}
            <div class="col-xl-4 col-lg-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="mdi mdi-shield-account-outline me-2 text-primary"></i>
                            Existing Roles
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        @forelse($roles as $role)
                            @php
                                $isSystem   = in_array($role->name, ['Admin', 'Editor', 'Viewer']);
                                $permCount  = $role->permissions->count();
                                $userCount  = $role->users_count ?? ($role->users ? $role->users->count() : 0);

                                $badgeClass = match($role->name) {
                                    'Admin'  => 'badge-soft-danger',
                                    'Editor' => 'badge-soft-warning',
                                    'Viewer' => 'badge-soft-info',
                                    default  => 'badge-soft-secondary',
                                };
                            @endphp
                            <div class="d-flex align-items-start p-3 border-bottom">
                                <div class="flex-grow-1 me-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold">{{ $role->name }}</span>
                                        <span class="badge {{ $badgeClass }} font-size-11">
                                            {{ $isSystem ? 'System' : 'Custom' }}
                                        </span>
                                    </div>
                                    <div class="d-flex gap-3">
                                        <small class="text-muted">
                                            <i class="mdi mdi-key-variant me-1"></i>
                                            {{ $permCount }} {{ Str::plural('permission', $permCount) }}
                                        </small>
                                        <small class="text-muted">
                                            <i class="mdi mdi-account-multiple-outline me-1"></i>
                                            {{ $userCount }} {{ Str::plural('user', $userCount) }}
                                        </small>
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    @if($isSystem)
                                        <button type="button"
                                                class="btn btn-outline-danger btn-sm"
                                                disabled
                                                title="System roles cannot be deleted">
                                            <i class="mdi mdi-lock-outline"></i>
                                        </button>
                                    @else
                                        <form action="{{ route('tenant.users.roles.destroy', [$tenant->slug, $role->id]) }}"
                                              method="POST"
                                              class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="btn btn-outline-danger btn-sm"
                                                    title="Delete role"
                                                    onclick="return confirm('Delete the &quot;{{ addslashes($role->name) }}&quot; role? Users with this role will be unassigned.')">
                                                <i class="mdi mdi-trash-can-outline"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-5 text-muted">
                                <i class="mdi mdi-shield-off-outline font-size-24 d-block mb-2"></i>
                                No roles defined yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- RIGHT PANEL — Create Custom Role                             --}}
            {{-- ============================================================ --}}
            <div class="col-xl-8 col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="mdi mdi-plus-circle-outline me-2 text-success"></i>
                            Create Custom Role
                        </h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('tenant.users.roles.create', $tenant->slug) }}"
                              method="POST"
                              id="createRoleForm">
                            @csrf

                            {{-- Role Name --}}
                            <div class="mb-4">
                                <label for="role_name" class="form-label">
                                    Role Name <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                       id="role_name"
                                       name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}"
                                       placeholder="e.g. Marketing Manager"
                                       required
                                       maxlength="100">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Permission Matrix --}}
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    Permissions
                                    <span class="text-muted fw-normal font-size-12 ms-1">
                                        — check the actions this role may perform
                                    </span>
                                </label>

                                @error('permissions')
                                    <div class="alert alert-danger py-2 mb-2">{{ $message }}</div>
                                @enderror

                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm align-middle mb-0" id="permissionMatrix">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-nowrap" style="min-width: 160px;">Module</th>
                                                <th class="text-center text-nowrap">View</th>
                                                <th class="text-center text-nowrap">Manage / Configure</th>
                                                <th class="text-center text-nowrap">Query</th>
                                                <th class="text-center text-nowrap">Export</th>
                                                <th class="text-center text-nowrap">Send</th>
                                            </tr>
                                        </thead>
                                        <tbody>

                                            {{-- -------------------------------------------------- --}}
                                            {{-- Each row: module label + relevant action columns   --}}
                                            {{-- Columns: View | Manage/Configure | Query | Export | Send --}}
                                            {{-- -------------------------------------------------- --}}

                                            @php
                                                /**
                                                 * Matrix definition.
                                                 * Keys: view | manage | query | export | send
                                                 * Value: permission name string, or null if N/A for this module.
                                                 */
                                                $matrix = [
                                                    'Analytics' => [
                                                        'view'   => 'analytics.view',
                                                        'manage' => 'analytics.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'AI Search' => [
                                                        'view'   => null,
                                                        'manage' => 'ai_search.manage',
                                                        'query'  => 'ai_search.query',
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'Marketing' => [
                                                        'view'   => 'marketing.view',
                                                        'manage' => 'marketing.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => 'marketing.send',
                                                    ],
                                                    'Business Intelligence' => [
                                                        'view'   => 'business_intelligence.view',
                                                        'manage' => 'business_intelligence.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'Chatbot' => [
                                                        'view'      => 'chatbot.view',
                                                        'manage'    => 'chatbot.configure',
                                                        'query'     => null,
                                                        'export'    => null,
                                                        'send'      => null,
                                                    ],
                                                    'CDP' => [
                                                        'view'   => 'cdp.view',
                                                        'manage' => 'cdp.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'DataSync' => [
                                                        'view'   => 'datasync.view',
                                                        'manage' => 'datasync.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'Users' => [
                                                        'view'   => 'users.view',
                                                        'manage' => 'users.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                    'Settings' => [
                                                        'view'   => null,
                                                        'manage' => 'settings.manage',
                                                        'query'  => null,
                                                        'export' => null,
                                                        'send'   => null,
                                                    ],
                                                ];

                                                $columns = ['view', 'manage', 'query', 'export', 'send'];
                                                $oldPermissions = old('permissions', []);
                                            @endphp

                                            @foreach($matrix as $moduleName => $modulePerms)
                                                <tr>
                                                    <td class="fw-medium text-nowrap">{{ $moduleName }}</td>
                                                    @foreach($columns as $col)
                                                        <td class="text-center">
                                                            @if($modulePerms[$col])
                                                                @php
                                                                    $permName = $modulePerms[$col];
                                                                    $isChecked = in_array($permName, $oldPermissions);
                                                                @endphp
                                                                <div class="form-check d-flex justify-content-center mb-0">
                                                                    <input class="form-check-input perm-checkbox"
                                                                           type="checkbox"
                                                                           name="permissions[]"
                                                                           value="{{ $permName }}"
                                                                           id="perm_{{ str_replace(['.', '_'], '_', $permName) }}"
                                                                           data-module="{{ $moduleName }}"
                                                                           {{ $isChecked ? 'checked' : '' }}>
                                                                </div>
                                                            @else
                                                                <span class="text-muted" title="Not applicable">
                                                                    <i class="mdi mdi-minus font-size-14"></i>
                                                                </span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach

                                        </tbody>
                                    </table>
                                </div>

                                {{-- Select / Deselect All --}}
                                <div class="mt-2 d-flex gap-3">
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 text-primary"
                                            id="selectAllPerms">
                                        <i class="mdi mdi-checkbox-multiple-marked-outline me-1"></i>
                                        Select all
                                    </button>
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 text-secondary"
                                            id="clearAllPerms">
                                        <i class="mdi mdi-checkbox-multiple-blank-outline me-1"></i>
                                        Clear all
                                    </button>
                                    <span class="text-muted ms-auto font-size-12" id="permCounter">
                                        0 selected
                                    </span>
                                </div>
                            </div>

                            <hr class="my-4">

                            {{-- Actions --}}
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="{{ route('tenant.users.index', $tenant->slug) }}"
                                   class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="mdi mdi-shield-plus-outline me-1"></i>
                                    Create Role
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>

        </div>{{-- /row --}}
    </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var checkboxes   = document.querySelectorAll('.perm-checkbox');
    var counterEl    = document.getElementById('permCounter');
    var selectAllBtn = document.getElementById('selectAllPerms');
    var clearAllBtn  = document.getElementById('clearAllPerms');

    function updateCounter() {
        var checked = document.querySelectorAll('.perm-checkbox:checked').length;
        counterEl.textContent = checked + ' selected';
    }

    // Update counter whenever a checkbox changes
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', updateCounter);
    });

    // Select all
    selectAllBtn.addEventListener('click', function () {
        checkboxes.forEach(function (cb) { cb.checked = true; });
        updateCounter();
    });

    // Clear all
    clearAllBtn.addEventListener('click', function () {
        checkboxes.forEach(function (cb) { cb.checked = false; });
        updateCounter();
    });

    // Initial count (restore old() state after validation failure)
    updateCounter();

    // Highlight table row on any checkbox interaction for usability
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            var row = cb.closest('tr');
            if (row) {
                row.classList.toggle('table-active', document.querySelectorAll('.perm-checkbox:checked', row).length > 0);
            }
        });
        // Initialise highlight state on load
        if (cb.checked) {
            var row = cb.closest('tr');
            if (row) row.classList.add('table-active');
        }
    });

}());
</script>
@endpush
