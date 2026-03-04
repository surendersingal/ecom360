<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8" />
    <title>@yield('title') | {{ $tenant->name ?? 'Ecom360' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Ecom360 Analytics Platform" name="description" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ URL::asset('build/images/favicon.ico') }}">
    @include('layouts.head-css')
    @stack('styles')
</head>

<body data-sidebar="dark" data-layout-mode="light">
    {{-- Impersonation Banner --}}
    @if(session('impersonating_from_admin_id'))
    <div class="alert alert-warning alert-dismissible mb-0 rounded-0 text-center" role="alert" style="z-index:9999; position:relative;">
        <i class="bx bx-user-voice me-1"></i>
        You are impersonating <strong>{{ session('impersonating_tenant_name', 'a tenant') }}</strong>.
        <a href="{{ route('admin.impersonate.stop') }}" class="alert-link ms-2">
            <i class="bx bx-arrow-back"></i> Back to Admin
        </a>
    </div>
    @endif

    <!-- Begin page -->
    <div id="layout-wrapper">
        @include('layouts.tenant-topbar')
        @include('layouts.tenant-sidebar')

        <!-- Start right Content here -->
        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">
                    @yield('content')
                </div>
            </div>
            @include('layouts.footer')
        </div>
    </div>
    <!-- END layout-wrapper -->

    @include('layouts.vendor-scripts')
    @stack('scripts')
</body>

</html>
