<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccountGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        // Session cookie set by Sanctum's stateful API middleware.
        $user = Auth::guard('web')->user();

        if (
            ! $user instanceof User
            || ! $user->isPartner()
            || $user->partner_status !== 'active'
        ) {
            return AccountGate::unauthenticated();
        }

        if ($rejected = AccountGate::reject($request, 'web', $user)) {
            return $rejected;
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
