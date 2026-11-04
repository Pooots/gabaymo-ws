<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $user = null;

        if (is_string($plain) && $plain !== '') {
            $user = User::query()
                ->where('api_token', hash('sha256', $plain))
                ->where('role', 'member')
                ->first();
        }

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Authentication required',
            ], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
