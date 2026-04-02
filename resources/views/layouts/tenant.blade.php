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
    <link href="{{ URL::asset('css/ecom360-redesign.css') }}?v={{ filemtime(public_path('css/ecom360-redesign.css')) }}" rel="stylesheet" type="text/css" />
    @stack('styles')
</head>

<body class="e360-tenant{{ session('impersonating_from_admin_id') ? ' e360-impersonating' : '' }}" data-sidebar="dark" data-layout-mode="light">
    {{-- Impersonation Banner --}}
    @if(session('impersonating_from_admin_id'))
    <div class="e360-impersonation-banner" style="position:fixed;top:0;left:0;right:0;z-index:9999;background:linear-gradient(90deg,#1E40AF,#3B82F6);color:#fff;text-align:center;padding:6px 16px;font-size:13px;font-weight:500;">
        <i class="bx bx-user-voice me-1" style="font-size:15px;vertical-align:middle;"></i>
        Viewing as <strong>{{ session('impersonating_tenant_name', 'a tenant') }}</strong>
        <a href="{{ route('admin.impersonate.stop') }}" style="color:#fff;margin-left:12px;text-decoration:underline;font-weight:600;">
            <i class="bx bx-arrow-back" style="font-size:12px;vertical-align:middle;"></i> Exit
        </a>
    </div>
    @endif

    <!-- Begin page -->
    <div id="layout-wrapper">
        @include('layouts.tenant-topbar')

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
    <script src="{{ URL::asset('js/ecom360-ui.js') }}?v={{ filemtime(public_path('js/ecom360-ui.js')) }}"></script>
    @stack('scripts')
</body>

</html>
