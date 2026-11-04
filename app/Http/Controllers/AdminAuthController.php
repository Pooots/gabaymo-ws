<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();
        $role = $user ? Role::query()->where('slug', $user->role)->first() : null;

        if (! $user || ! $role || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        $plain = Str::random(64);
        $user->forceFill([
            'api_token' => hash('sha256', $plain),
        ])->save();

        $request->setUserResolver(static fn () => $user);
        AdminOverviewController::record($request, 'admin.login', $user->name.' signed in');

        return response()->json([
            'token' => $plain,
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
        $request->user()?->forceFill(['api_token' => null])->save();

        return response()->json(['ok' => true]);
    }

    private function profile(User $user): array
    {
        $role = Role::query()->where('slug', $user->role)->first();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_name' => $role?->name ?? 'Administrator',
            'permissions' => $role?->granted() ?? AdminPermissions::keys(),
        ];
    }
}
