<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * Handle login attempt.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            $user = Auth::user();

            // Create a Sanctum API token for dashboard JS calls.
            // Stored in session so blade views can embed it as:
            //   const TOKEN = '{{ session("api_token") }}'
            // Revoke any previous dashboard-session tokens first to avoid accumulation.
            $user->tokens()->where('name', 'dashboard-session')->delete();
            $token = $user->createToken('dashboard-session')->plainTextToken;
            $request->session()->put('api_token', $token);

            // Super admin → admin panel
            if ($user->is_super_admin) {
                return redirect()->intended(route('admin.dashboard'));
            }

            // Tenant user → tenant panel
            if ($user->tenant_id) {
                $tenant = $user->tenant;
                if ($tenant) {
                    return redirect()->intended(route('tenant.dashboard', $tenant->slug));
                }
            }

            // Fallback
            return redirect('/');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Logout — revoke the dashboard Sanctum token and destroy session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        // Revoke the dashboard-session token so it can no longer be used.
        if ($user) {
            $user->tokens()->where('name', 'dashboard-session')->delete();
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
