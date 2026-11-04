<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $user = null;

        if (is_string($plain) && $plain !== '') {
            $user = User::query()
                ->where('api_token', hash('sha256', $plain))
                ->where('role', 'partner')
                ->where('type', 'partners')
                ->first();
        }

        if (! $user || $user->partner_status !== 'active') {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Authentication required',
            ], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
