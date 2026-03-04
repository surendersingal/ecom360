<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8" />
    <title>@yield('title') | Ecom360</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Ecom360 Analytics Platform" name="description" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ URL::asset('build/images/favicon.ico') }}">
    <!-- Bootstrap Css -->
    <link href="{{ URL::asset('build/css/bootstrap.min.css') }}" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="{{ URL::asset('build/css/icons.min.css') }}" rel="stylesheet" type="text/css" />
    <!-- App Css -->
    <link href="{{ URL::asset('build/css/app.min.css') }}" rel="stylesheet" type="text/css" />
    <!-- Custom Css -->
    <link href="{{ URL::asset('build/css/custom.min.css') }}" rel="stylesheet" type="text/css" />
    @yield('css')
</head>

<body>
    <div class="account-pages my-5 pt-sm-5">
        @yield('content')
    </div>

    <!-- JAVASCRIPT -->
    <script src="{{ URL::asset('build/libs/jquery/jquery.min.js') }}"></script>
    <script src="{{ URL::asset('build/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ URL::asset('build/js/app.js') }}"></script>
    @yield('script')
</body>

</html>
