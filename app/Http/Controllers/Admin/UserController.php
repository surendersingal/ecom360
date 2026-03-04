<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        $users = User::with('tenant')->latest()->get();
        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $tenants = Tenant::where('is_active', true)->orderBy('name')->get();
        return view('admin.users.create', compact('tenants'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8'],
            'tenant_id'      => ['nullable', 'exists:tenants,id'],
            'is_super_admin' => ['boolean'],
        ]);

        $validated['password']       = bcrypt($validated['password']);
        $validated['is_super_admin'] = $request->boolean('is_super_admin', false);

        User::create($validated);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        $tenants = Tenant::where('is_active', true)->orderBy('name')->get();
        return view('admin.users.edit', compact('user', 'tenants'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'unique:users,email,' . $user->id],
            'tenant_id'      => ['nullable', 'exists:tenants,id'],
            'is_super_admin' => ['boolean'],
        ]);

        $validated['is_super_admin'] = $request->boolean('is_super_admin', false);

        if ($request->filled('password')) {
            $validated['password'] = bcrypt($request->input('password'));
        }

        $user->update($validated);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            return back()->with('error', 'Cannot delete the last super admin.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}
