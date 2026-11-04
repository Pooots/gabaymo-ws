<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $user = null;

        if (is_string($plain) && $plain !== '') {
            $user = User::query()
                ->where('api_token', hash('sha256', $plain))
                ->first();
        }

        $role = $user ? Role::query()->where('slug', $user->role)->first() : null;

        if (! $user || (! $role && $user->role !== 'admin')) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Authentication required',
            ], 401);
        }

        if ($role && ! $this->permitted($request, $role)) {
            return response()->json([
                'message' => 'You do not have access to this module.',
            ], 403);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function permitted(Request $request, Role $role): bool
    {
        $tail = preg_replace('#^api/v1/admin/?#', '', $request->path()) ?? '';
        $segment = explode('/', $tail)[0] ?? '';

        if ($segment === 'knowledge-documents') {
            if (str_contains($tail, '/review')) {
                return $role->allows('source-review');
            }

            if ($request->isMethod('GET')) {
                return $role->allowsAny(['knowledge-library', 'source-review', 'knowledge-search']);
            }

            return $role->allows('knowledge-library');
        }

        $key = match ($segment) {
            '', 'me', 'logout' => null,
            'overview' => 'overview',
            'audit' => 'audit-log',
            'subscriptions' => 'subscriptions',
            'service-instructions' => 'service-instructions',
            'roles' => 'roles',
            'members' => 'members',
            'partners' => 'partners',
            'tickets' => 'tickets',
            default => null,
        };

        return $key === null || $role->allows($key);
    }
}
