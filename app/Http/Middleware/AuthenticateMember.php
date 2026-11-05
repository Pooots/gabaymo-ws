<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMember
{
    public function handle(Request $request, Closure $next): Response
    {
        // Session cookie set by Sanctum's stateful API middleware.
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->isCustomer()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Authentication required',
            ], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
