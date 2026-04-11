<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust all proxies (Cloudflare, ngrok, etc.) so HTTPS detection works behind tunnels
        $middleware->trustProxies(at: '*');

        // Enable Sanctum session-based auth for same-origin API requests
        $middleware->statefulApi();

        // Force JSON responses on all /api/* routes so validation errors return
        // HTTP 422 JSON instead of HTTP 302 redirects when Accept header is absent.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'super_admin'       => \App\Http\Middleware\EnsureSuperAdmin::class,
            'resolve_tenant'    => \App\Http\Middleware\ResolveTenant::class,
            'tenant.permission' => \App\Http\Middleware\RequireTenantPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
