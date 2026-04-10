@extends('layouts.admin')

@section('title', 'Edit User - ' . $user->name)

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Edit User</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Users</a></li>
                        <li class="breadcrumb-item active">{{ $user->name }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">User Details</h4>
                    <form action="{{ route('admin.users.update', $user) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $user->name) }}" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email', $user->email) }}" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">
                                New Password <small class="text-muted">(leave blank to keep current)</small>
                            </label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" autocomplete="new-password">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4" id="password-confirm-wrapper">
                            <label for="password_confirmation" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control"
                                   id="password_confirmation" name="password_confirmation" autocomplete="new-password">
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch form-switch-lg">
                                <input class="form-check-input" type="checkbox" id="is_super_admin" name="is_super_admin" value="1"
                                       {{ old('is_super_admin', $user->is_super_admin) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_super_admin">Super Admin</label>
                            </div>
                            <small class="text-muted">Super admins have full access to all stores. Tenant &amp; role are not required.</small>
                        </div>

                        {{-- Tenant + Role section — hidden when Super Admin is checked --}}
                        <div id="tenant-role-section">
                            <hr class="mb-4">
                            <h5 class="mb-3 text-muted font-size-14">Store Assignment &amp; Role</h5>

                            <div class="mb-3">
                                <label for="tenant_id" class="form-label">Assign to Store <span class="text-danger">*</span></label>
                                <select class="form-select @error('tenant_id') is-invalid @enderror" id="tenant_id" name="tenant_id">
                                    <option value="">— Select a Store —</option>
                                    @foreach($tenants as $tenant)
                                        <option value="{{ $tenant->id }}" {{ old('tenant_id', $user->tenant_id) == $tenant->id ? 'selected' : '' }}>
                                            {{ $tenant->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('tenant_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select @error('role') is-invalid @enderror" id="role" name="role">
                                    <option value="">— Select a Role —</option>
                                    @foreach($roles as $roleOption)
                                        <option value="{{ $roleOption }}"
                                            {{ old('role', $currentRole) == $roleOption ? 'selected' : '' }}>
                                            {{ $roleOption }}
                                            @if($roleOption === 'Admin') — Full manage access
                                            @elseif($roleOption === 'Editor') — View, export &amp; use AI tools
                                            @else — Read-only access
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="mt-2 p-3 bg-light rounded">
                                    <small class="text-muted">
                                        <strong>Admin:</strong> Full control — analytics, AI search, BI, chatbot, marketing (manage &amp; configure)<br>
                                        <strong>Editor:</strong> View &amp; export analytics, use AI search/chatbot, view BI &amp; marketing<br>
                                        <strong>Viewer:</strong> Read-only — analytics view, AI search queries, BI view only
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary waves-effect waves-light">
                                <i class="bx bx-save me-1"></i> Update User
                            </button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary waves-effect">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const superAdminToggle  = document.getElementById('is_super_admin');
    const tenantRoleSection = document.getElementById('tenant-role-section');
    const tenantSelect      = document.getElementById('tenant_id');
    const roleSelect        = document.getElementById('role');
    const passwordField     = document.getElementById('password');
    const confirmWrapper    = document.getElementById('password-confirm-wrapper');
    const confirmField      = document.getElementById('password_confirmation');

    function toggleTenantRole() {
        const isSuperAdmin = superAdminToggle.checked;
        tenantRoleSection.style.display = isSuperAdmin ? 'none' : 'block';
        tenantSelect.required = !isSuperAdmin;
        roleSelect.required   = !isSuperAdmin;
    }

    // Show confirm password field only when a new password is being typed
    function toggleConfirm() {
        const hasPassword = passwordField.value.length > 0;
        confirmWrapper.style.display = hasPassword ? 'block' : 'none';
        confirmField.required = hasPassword;
    }

    superAdminToggle.addEventListener('change', toggleTenantRole);
    passwordField.addEventListener('input', toggleConfirm);

    toggleTenantRole();
    toggleConfirm();
})();
</script>
@endpush
