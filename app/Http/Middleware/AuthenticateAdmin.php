<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AccountGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    /**
     * First path segment after /api/v1/admin => module permission(s) (any of).
     * Default-deny: a segment that is not listed here is refused, so every new
     * admin route must be mapped to a permission.
     *  - [] : any signed-in staff account (own profile, sign-out)
     *  - null: the controller checks permissions per route (AI gateway)
     */
    private const SEGMENTS = [
        '' => [],
        'me' => [],
        'logout' => [],
        'overview' => ['overview'],
        'audit' => ['audit-log'],
        'subscriptions' => ['subscriptions', 'plans'],
        'service-instructions' => ['service-instructions'],
        // Includes roles/{role}/accounts, .../reset-password, .../resend-invite, .../suspend and .../unsuspend.
        'roles' => ['roles'],
        'members' => ['members'],
        // Includes partners/{partner}/reset-password and .../resend-invite.
        'partners' => ['partners'],
        'tickets' => ['tickets'],
        'payments' => ['payments'],
        'access-keys' => ['access-keys'],
        'referral-codes' => ['referral-codes'],
        // Includes DELETE organizations/{organization}.
        'organizations' => ['organizations'],
        'ai' => null,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Staff session (admin_users table), separate from customers/partners.
        $user = Auth::guard('admin')->user();
        $role = $user instanceof AdminUser ? $user->role : null;

        if (! $role) {
            return AccountGate::unauthenticated();
        }

        if ($rejected = AccountGate::reject($request, 'admin', $user)) {
            return $rejected;
        }

        $tail = trim(preg_replace('#^api/v1/admin/?#', '', $request->path()) ?? '', '/');
        if (! $this->permitted($request, $tail, $role)) {
            return response()->json([
                'message' => 'You do not have access to this module.',
            ], 403);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function permitted(Request $request, string $tail, Role $role): bool
    {
        $segment = explode('/', $tail)[0] ?? '';

        // Legacy text-only library (see AdminKnowledgeController).
        if ($segment === 'knowledge-documents') {
            if (str_contains($tail, '/review')) {
                return $role->allows('source-review');
            }

            if ($request->isMethod('GET')) {
                return $role->allowsAny(['knowledge-library', 'source-review', 'knowledge-search']);
            }

            return $role->allows('knowledge-library');
        }

        if (! array_key_exists($segment, self::SEGMENTS)) {
            return false;
        }

        $modules = self::SEGMENTS[$segment];

        return $modules === null || $modules === [] || $role->allowsAny($modules);
    }
}
