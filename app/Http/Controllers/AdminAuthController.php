<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        SpaSession::requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = AdminUser::query()
            ->with('role')
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! $user->role || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        SpaSession::login($request, 'admin', $user);
        $user->forceFill(['last_login_at' => now()])->save();
        AdminOverviewController::record($request, 'admin.login', $user->name.' signed in');

        return response()->json([
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->profile($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        SpaSession::logout($request, 'admin');

        return response()->json(['ok' => true]);
    }

    private function profile(AdminUser $user): array
    {
        $role = $user->role;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role->slug,
            'role_name' => $role->name,
            'permissions' => $role->granted(),
        ];
    }
}
