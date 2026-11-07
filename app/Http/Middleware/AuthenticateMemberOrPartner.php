<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccountGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared routes (notifications, websocket auth) for any signed-in member
 * or active partner on the Sanctum session cookie.
 */
class AuthenticateMemberOrPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        $allowed = $user instanceof User
            && ($user->isCustomer() || ($user->isPartner() && $user->partner_status === 'active'));

        if (! $allowed) {
            return AccountGate::unauthenticated();
        }

        if ($rejected = AccountGate::reject($request, 'web', $user)) {
            return $rejected;
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
