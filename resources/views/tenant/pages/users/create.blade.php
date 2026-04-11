@extends('layouts.tenant')

@section('content')

<div class="page-content">
    <div class="container-fluid">

        {{-- Page Title --}}
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Add User</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="#">{{ $tenant->name }}</a></li>
                            <li class="breadcrumb-item">
                                <a href="{{ route('tenant.users.index', $tenant->slug) }}">Users</a>
                            </li>
                            <li class="breadcrumb-item active">Add User</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-10">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h4 class="card-title mb-0">New User Details</h4>
                        <a href="{{ route('tenant.users.index', $tenant->slug) }}"
                           class="btn btn-outline-secondary btn-sm">
                            <i class="mdi mdi-arrow-left me-1"></i>
                            Back to Users
                        </a>
                    </div>

                    <div class="card-body">
                        <form action="{{ route('tenant.users.store', $tenant->slug) }}"
                              method="POST"
                              novalidate>
                            @csrf

                            {{-- Name --}}
                            <div class="mb-3">
                                <label for="name" class="form-label">
                                    Full Name <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                       id="name"
                                       name="name"
                                       class="form-control @error('name') is-invalid @enderror"
                                       value="{{ old('name') }}"
                                       placeholder="Enter full name"
                                       required
                                       autofocus>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Email --}}
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    Email Address <span class="text-danger">*</span>
                                </label>
                                <input type="email"
                                       id="email"
                                       name="email"
                                       class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email') }}"
                                       placeholder="user@example.com"
                                       required>
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Password --}}
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password"
                                           id="password"
                                           name="password"
                                           class="form-control @error('password') is-invalid @enderror"
                                           placeholder="Minimum 8 characters"
                                           minlength="8"
                                           required>
                                    <button class="btn btn-outline-secondary"
                                            type="button"
                                            id="togglePassword"
                                            title="Show/hide password">
                                        <i class="mdi mdi-eye-outline" id="togglePasswordIcon"></i>
                                    </button>
                                    @error('password')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="form-text">Must be at least 8 characters.</div>
                            </div>

                            {{-- Confirm Password --}}
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">
                                    Confirm Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="password"
                                           id="password_confirmation"
                                           name="password_confirmation"
                                           class="form-control @error('password_confirmation') is-invalid @enderror"
                                           placeholder="Re-enter password"
                                           required>
                                    <button class="btn btn-outline-secondary"
                                            type="button"
                                            id="togglePasswordConfirm"
                                            title="Show/hide password">
                                        <i class="mdi mdi-eye-outline" id="togglePasswordConfirmIcon"></i>
                                    </button>
                                    @error('password_confirmation')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Role --}}
                            <div class="mb-4">
                                <label for="role_id" class="form-label">
                                    Role <span class="text-danger">*</span>
                                </label>
                                <select id="role_id"
                                        name="role_id"
                                        class="form-select @error('role_id') is-invalid @enderror"
                                        required>
                                    <option value="" disabled {{ old('role_id') ? '' : 'selected' }}>
                                        -- Select a role --
                                    </option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->id }}"
                                                {{ old('role_id') == $role->id ? 'selected' : '' }}>
                                            {{ $role->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('role_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror

                                {{-- Role descriptions shown below the select --}}
                                <div id="roleDescriptions" class="mt-2">
                                    @foreach($roles as $role)
                                        @php
                                            $description = match($role->name) {
                                                'Admin'  => 'Full access to all features and settings within this tenant.',
                                                'Editor' => 'Can create and edit content but cannot manage users or settings.',
                                                'Viewer' => 'Read-only access to permitted modules.',
                                                default  => 'Custom role with individually configured permissions.',
                                            };
                                        @endphp
                                        <small class="text-muted role-desc d-none"
                                               data-role-id="{{ $role->id }}">
                                            <i class="mdi mdi-information-outline me-1"></i>
                                            {{ $description }}
                                        </small>
                                    @endforeach
                                </div>
                            </div>

                            <hr class="my-4">

                            {{-- Actions --}}
                            <div class="d-flex gap-2 justify-content-end">
                                <a href="{{ route('tenant.users.index', $tenant->slug) }}"
                                   class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="mdi mdi-account-plus-outline me-1"></i>
                                    Create User
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    // Toggle password visibility
    function bindToggle(btnId, inputId, iconId) {
        var btn   = document.getElementById(btnId);
        var input = document.getElementById(inputId);
        var icon  = document.getElementById(iconId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.classList.toggle('mdi-eye-outline',     !isPassword);
            icon.classList.toggle('mdi-eye-off-outline',  isPassword);
        });
    }

    bindToggle('togglePassword',        'password',              'togglePasswordIcon');
    bindToggle('togglePasswordConfirm', 'password_confirmation', 'togglePasswordConfirmIcon');

    // Show role description based on selected role
    var roleSelect = document.getElementById('role_id');
    var roleDescs  = document.querySelectorAll('.role-desc');

    function updateRoleDescription() {
        var selectedId = roleSelect.value;
        roleDescs.forEach(function (el) {
            el.classList.toggle('d-none', el.dataset.roleId !== selectedId);
        });
    }

    roleSelect.addEventListener('change', updateRoleDescription);

    // Restore on page load if old() value is present
    if (roleSelect.value) {
        updateRoleDescription();
    }
}());
</script>
@endpush
