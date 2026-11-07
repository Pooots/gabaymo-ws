<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Checks shared by the member, partner, notifiable and admin middleware. */
class AccountGate
{
    /** A response that stops the request, or null when the session may continue. */
    public static function reject(Request $request, string $guard, User|AdminUser $user): ?JsonResponse
    {
        if (! SpaSession::isCurrent($request, $guard, $user)) {
            if ($request->hasSession()) {
                Auth::guard($guard)->logout();
            }

            return self::unauthenticated();
        }

        // Invited accounts never get a session; this only guards stale ones.
        if ($user->isInvited()) {
            if ($request->hasSession()) {
                Auth::guard($guard)->logout();
            }

            return self::unauthenticated();
        }

        if ($user->isSuspended()) {
            if ($user instanceof AdminUser && $request->hasSession()) {
                Auth::guard($guard)->logout();
            }

            return response()->json([
                'message' => 'This account is suspended. Contact GabayMo support.',
                'error' => 'account_suspended',
            ], 403);
        }

        return null;
    }

    public static function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
            'error' => 'Authentication required',
        ], 401);
    }
}
