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
        // Remember which "session generation" this sign-in belongs to (see isCurrent()).
        $request->session()->put(self::versionKey($guard), (int) $user->session_version);
        $request->setUserResolver(static fn () => $user);
    }

    /**
     * False when the account was signed out everywhere (logout-all, password
     * change or reset, suspension) after this session signed in.
     */
    public static function isCurrent(Request $request, string $guard, User|AdminUser $user): bool
    {
        $stored = $request->hasSession() ? $request->session()->get(self::versionKey($guard)) : null;

        return (int) ($stored ?? 0) === (int) $user->session_version;
    }

    /** Invalidate every session of the account; optionally keep the current one signed in. */
    public static function revokeAll(Request $request, string $guard, User|AdminUser $user, bool $keepCurrent = false): void
    {
        $user->forceFill(['session_version' => (int) $user->session_version + 1])->save();

        if ($keepCurrent && $request->hasSession()) {
            $request->session()->put(self::versionKey($guard), (int) $user->session_version);
        }
    }

    private static function versionKey(string $guard): string
    {
        return 'auth_version_'.$guard;
    }

    public static function logout(Request $request, string $guard): void
    {
        if (! $request->hasSession()) {
            Auth::guard($guard)->forgetUser();

            return;
        }

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
