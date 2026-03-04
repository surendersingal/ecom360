<!-- ========== Left Sidebar Start ========== -->
<div class="vertical-menu">
    <div data-simplebar class="h-100">
        <div id="sidebar-menu">
            <ul class="metismenu list-unstyled" id="side-menu">

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   MAIN
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-main">Main</li>

                <li>
                    <a href="{{ route('admin.dashboard') }}" class="waves-effect {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                        <i class="bx bxs-dashboard"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   PLATFORM ANALYTICS
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-analytics">Analytics</li>

                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect {{ request()->routeIs('admin.analytics.*') ? 'mm-active' : '' }}">
                        <i class="bx bx-bar-chart-alt-2"></i>
                        <span>Analytics</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="{{ route('admin.analytics.platform') }}" class="{{ request()->routeIs('admin.analytics.platform') ? 'active' : '' }}">Platform Overview</a></li>
                        <li><a href="{{ route('admin.analytics.tenants') }}" class="{{ request()->routeIs('admin.analytics.tenants') ? 'active' : '' }}">Tenant Analytics</a></li>
                        <li><a href="{{ route('admin.analytics.revenue') }}" class="{{ request()->routeIs('admin.analytics.revenue') ? 'active' : '' }}">Revenue Overview</a></li>
                        <li><a href="{{ route('admin.analytics.benchmarking') }}" class="{{ request()->routeIs('admin.analytics.benchmarking') ? 'active' : '' }}">Cross-Tenant Benchmarking</a></li>
                    </ul>
                </li>

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   MANAGE
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-manage">Manage</li>

                <li>
                    <a href="{{ route('admin.tenants.index') }}" class="waves-effect {{ request()->routeIs('admin.tenants.*') ? 'active' : '' }}">
                        <i class="bx bx-buildings"></i>
                        <span>Stores</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.users.index') }}" class="waves-effect {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <i class="bx bx-user"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.roles') }}" class="waves-effect {{ request()->routeIs('admin.roles*') ? 'active' : '' }}">
                        <i class="bx bx-shield-quarter"></i>
                        <span>Roles & Permissions</span>
                    </a>
                </li>

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   MONITORING
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-monitoring">Monitoring</li>

                <li>
                    <a href="{{ route('admin.activity-log') }}" class="waves-effect {{ request()->routeIs('admin.activity-log') ? 'active' : '' }}">
                        <i class="bx bx-list-ul"></i>
                        <span>Activity Log</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.system-health') }}" class="waves-effect {{ request()->routeIs('admin.system-health') ? 'active' : '' }}">
                        <i class="bx bx-heart"></i>
                        <span>System Health</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.queue-monitor') }}" class="waves-effect {{ request()->routeIs('admin.queue-monitor') ? 'active' : '' }}">
                        <i class="bx bx-loader-circle"></i>
                        <span>Queue Monitor</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.event-bus') }}" class="waves-effect {{ request()->routeIs('admin.event-bus') ? 'active' : '' }}">
                        <i class="bx bx-transfer-alt"></i>
                        <span>Event Bus</span>
                    </a>
                </li>

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   INFRASTRUCTURE
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-infra">Infrastructure</li>

                <li>
                    <a href="{{ route('admin.modules') }}" class="waves-effect {{ request()->routeIs('admin.modules') ? 'active' : '' }}">
                        <i class="bx bx-extension"></i>
                        <span>Module Manager</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.data-management') }}" class="waves-effect {{ request()->routeIs('admin.data-management') ? 'active' : '' }}">
                        <i class="bx bx-data"></i>
                        <span>Data Management</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.datasync') }}" class="waves-effect {{ request()->routeIs('admin.datasync') ? 'active' : '' }}">
                        <i class="bx bx-transfer"></i>
                        <span>Data Sync</span>
                    </a>
                </li>

                {{-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                   CONFIGURATION
                ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
                <li class="menu-title" key="t-config">Configuration</li>

                <li>
                    <a href="{{ route('admin.settings') }}" class="waves-effect {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                        <i class="bx bx-cog"></i>
                        <span>Platform Settings</span>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</div>
<!-- Left Sidebar End -->
