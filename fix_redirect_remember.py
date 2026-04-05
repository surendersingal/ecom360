import re

# ============================================================
# FIX 1: Redirect loop - Make "/" route auth-aware
# ============================================================
web_path = "/var/www/ecom360/routes/web.php"
with open(web_path, "r") as f:
    content = f.read()

old_root_route = """// ─── Public ───
Route::get('/', function () {
    return redirect()->route('login');
});"""

new_root_route = """// ─── Public ───
Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->is_super_admin) {
            return redirect()->route('admin.dashboard');
        }
        if ($user->tenant_id) {
            $tenant = $user->tenant;
            if ($tenant) {
                return redirect()->route('tenant.dashboard', $tenant->slug);
            }
        }
    }
    return redirect()->route('login');
});"""

if old_root_route in content:
    content = content.replace(old_root_route, new_root_route)
    with open(web_path, "w") as f:
        f.write(content)
    print("FIX 1 OK: Root route now auth-aware")
else:
    print("FIX 1 SKIP: Root route pattern not found")
    if "auth()->check()" in content:
        print("  Already fixed!")
    else:
        # Show what the root route looks like
        idx = content.find("Route::get('/'")
        if idx >= 0:
            print("  Current root route:")
            print(content[idx:idx+200])

# ============================================================
# FIX 2: Create RollingRememberMe middleware
# ============================================================
middleware_path = "/var/www/ecom360/app/Http/Middleware/RollingRememberMe.php"
middleware_content = r"""<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rolling "Remember Me" middleware.
 *
 * When a user logs in with "Remember Me" checked, their remember-token
 * cookie is refreshed on every request with a rolling 1-day expiry.
 * This means if they visit within 24 hours, the cookie extends another day.
 * If they don't visit for 24 hours, the cookie expires and they must re-login.
 */
final class RollingRememberMe
{
    private const REMEMBER_LIFETIME_MINUTES = 1440; // 1 day

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only process for authenticated users
        if (! Auth::check()) {
            return $response;
        }

        // Find the remember-me cookie (named "remember_web_<hash>")
        $cookieName = Auth::guard()->getRecallerName();
        $cookieValue = $request->cookies->get($cookieName);

        if ($cookieValue) {
            // Re-set the same remember cookie with a fresh 1-day expiry
            $cookie = Cookie::make(
                $cookieName,
                $cookieValue,
                self::REMEMBER_LIFETIME_MINUTES, // 1 day
                config('session.path', '/'),
                config('session.domain'),
                config('session.secure', true),
                true, // httpOnly
                false,
                config('session.same_site', 'lax')
            );

            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
"""

with open(middleware_path, "w") as f:
    f.write(middleware_content)
print("FIX 2a OK: RollingRememberMe middleware created")

# ============================================================
# FIX 3: Register middleware in bootstrap/app.php
# ============================================================
bootstrap_path = "/var/www/ecom360/bootstrap/app.php"
with open(bootstrap_path, "r") as f:
    content = f.read()

if "RollingRememberMe" not in content:
    # Insert the appendToGroup line before the alias block
    old_alias = "        $middleware->alias(["
    new_block = """        // Rolling "Remember Me" - extends cookie by 1 day on each visit
        $middleware->appendToGroup('web', \\App\\Http\\Middleware\\RollingRememberMe::class);

        $middleware->alias(["""
    
    if old_alias in content:
        content = content.replace(old_alias, new_block, 1)
        with open(bootstrap_path, "w") as f:
            f.write(content)
        print("FIX 2b OK: RollingRememberMe registered in bootstrap/app.php")
    else:
        print("FIX 2b SKIP: alias pattern not found in bootstrap/app.php")
else:
    print("FIX 2b SKIP: RollingRememberMe already registered")

# ============================================================
# FIX 4: Override remember-me cookie lifetime in LoginController
# ============================================================
login_path = "/var/www/ecom360/app/Http/Controllers/Auth/LoginController.php"
with open(login_path, "r") as f:
    content = f.read()

old_login = """        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();"""

new_login = """        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            // If "Remember Me" checked, extend session lifetime to 1 day (rolling)
            if ($remember) {
                config(['session.lifetime' => 1440]);
            }"""

if "session.lifetime" not in content and old_login in content:
    content = content.replace(old_login, new_login)
    with open(login_path, "w") as f:
        f.write(content)
    print("FIX 3 OK: LoginController updated with 1-day session for remember me")
elif "session.lifetime" in content:
    print("FIX 3 SKIP: Already has session.lifetime override")
else:
    print("FIX 3 SKIP: LoginController pattern not found")
    # Show what exists
    idx = content.find("Auth::attempt")
    if idx >= 0:
        print("  Current Auth::attempt block:")
        print(content[idx:idx+200])

print("\n=== ALL FIXES COMPLETE ===")
