<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8" />
    <title>@yield('title') | Ecom360 Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Ecom360 Analytics Platform" name="description" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ URL::asset('build/images/favicon.ico') }}">
    @include('layouts.head-css')
    @stack('styles')
</head>

<body data-sidebar="dark" data-layout-mode="light">
    <!-- Begin page -->
    <div id="layout-wrapper">
        @include('layouts.admin-topbar')
        @include('layouts.admin-sidebar')

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
