<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $stats = [
            'total_tenants'   => Tenant::count(),
            'active_tenants'  => Tenant::where('is_active', true)->count(),
            'verified_tenants'=> Tenant::where('is_verified', true)->count(),
            'total_users'     => User::where('is_super_admin', false)->count(),
            'admin_users'     => User::where('is_super_admin', true)->count(),
        ];

        $recentTenants = Tenant::latest()->take(10)->get();
        $recentUsers   = User::where('is_super_admin', false)->latest()->take(10)->get();

        return view('admin.dashboard', compact('stats', 'recentTenants', 'recentUsers'));
    }
}
