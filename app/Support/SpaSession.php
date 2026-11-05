<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cookie-session sign-in for the SPA portals (Laravel Sanctum, stateful mode).
 *
 * Guards:
 *  - "web"   customers and partners (shared /login)
 *  - "admin" staff (admin_users table)
 *
 * Both guards live in the same session, so signing out of one only removes
 * that guard instead of destroying the whole session.
 */
class SpaSession
{
    /**
     * Sanctum only starts a session for requests whose Origin/Referer is in
     * SANCTUM_STATEFUL_DOMAINS (Postman, curl and unknown hosts get none).
     */
    public static function requireSession(Request $request): void
    {
        if (! $request->hasSession()) {
            abort(response()->json([
                'message' => 'Sign in from a Gabaymo app. This origin is not allowed to start a session.',
            ], 400));
        }
    }

    public static function login(Request $request, string $guard, User|AdminUser $user): void
    {
        static::requireSession($request);

        Auth::guard($guard)->login($user);

        // Prevent session fixation.
        $request->session()->regenerate();
        $request->setUserResolver(static fn () => $user);
    }

    public static function logout(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }

    /**
     * Which portal a customer/partner account belongs to.
     */
    public static function portal(User $user): string
    {
        return $user->isPartner() ? 'partner' : 'customer';
    }
}
