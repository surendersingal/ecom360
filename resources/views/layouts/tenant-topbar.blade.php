<header id="page-topbar">
    <div class="navbar-header">
        <div class="d-flex">
            <!-- LOGO -->
            <div class="navbar-brand-box">
                <a href="{{ route('tenant.dashboard', $tenant->slug) }}" class="logo logo-dark">
                    <span class="logo-sm">
                        <i class="bx bx-store-alt text-primary" style="font-size:22px"></i>
                    </span>
                    <span class="logo-lg">
                        <span class="text-primary fw-bold" style="font-size:16px">{{ Str::limit($tenant->name, 18) }}</span>
                    </span>
                </a>
                <a href="{{ route('tenant.dashboard', $tenant->slug) }}" class="logo logo-light">
                    <span class="logo-sm">
                        <i class="bx bx-store-alt text-white" style="font-size:22px"></i>
                    </span>
                    <span class="logo-lg">
                        <span class="text-white fw-bold" style="font-size:16px">{{ Str::limit($tenant->name, 18) }}</span>
                    </span>
                </a>
            </div>

            <button type="button" class="btn btn-sm px-3 font-size-16 header-item waves-effect" id="vertical-menu-btn">
                <i class="fa fa-fw fa-bars"></i>
            </button>
        </div>

        <div class="d-flex">
            <!-- Fullscreen -->
            <div class="dropdown d-none d-lg-inline-block ms-1">
                <button type="button" class="btn header-item noti-icon waves-effect" data-bs-toggle="fullscreen">
                    <i class="bx bx-fullscreen"></i>
                </button>
            </div>

            <!-- User Dropdown -->
            <div class="dropdown d-inline-block">
                <button type="button" class="btn header-item waves-effect" id="page-header-user-dropdown"
                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <img class="rounded-circle header-profile-user" src="{{ URL::asset('build/images/users/avatar-1.jpg') }}" alt="Avatar">
                    <span class="d-none d-xl-inline-block ms-1">{{ Auth::user()->name ?? 'User' }}</span>
                    <i class="mdi mdi-chevron-down d-none d-xl-inline-block"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <a class="dropdown-item" href="#">
                        <i class="bx bx-user font-size-16 align-middle me-1"></i> Profile
                    </a>
                    <a class="dropdown-item" href="{{ route('tenant.settings', $tenant->slug) }}">
                        <i class="bx bx-cog font-size-16 align-middle me-1"></i> Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="#"
                       onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                        <i class="bx bx-power-off font-size-16 align-middle me-1 text-danger"></i> Logout
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                        @csrf
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
