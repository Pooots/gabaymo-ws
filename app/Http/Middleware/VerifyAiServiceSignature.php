<?php

namespace App\Http\Middleware;

use App\Services\GabayMoAi\ServiceSignature as Sig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound calls from gabaymo-ai (credit settlement, handoff tickets).
 * Same signed-assertion scheme as ws -> ai, with its own secret and the
 * gabaymo-ws audience. Grants only the scopes listed on the route.
 */
class VerifyAiServiceSignature
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $cfg = (array) config('services.gabaymo_ai');
        $secrets = (array) ($cfg['inbound_keys'] ?? []);
        $service = (string) $request->header(Sig::H_SERVICE, '');
        $timestamp = (string) $request->header(Sig::H_TIMESTAMP, '');
        $nonce = (string) $request->header(Sig::H_NONCE, '');
        $context = (string) $request->header(Sig::H_CONTEXT, '');
        $signature = (string) $request->header(Sig::H_SIGNATURE, '');
        $audience = (string) $request->header(Sig::H_AUDIENCE, '');

        $deny = function (string $reason, int $status = 401) use ($request) {
            Log::warning('AI service call rejected.', ['reason' => $reason, 'path' => $request->path()]);

            return response()->json(['message' => 'Unauthenticated service request.', 'error' => $reason], $status);
        };

        if ($secrets === [] || $service !== 'gabaymo-ai' || $signature === '' || $nonce === '' || $context === '') {
            return $deny('missing_signature');
        }
        if (! hash_equals((string) ($cfg['self_audience'] ?? 'gabaymo-ws'), $audience)) {
            return $deny('wrong_audience');
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) ($cfg['clock_skew'] ?? 60)) {
            return $deny('expired_signature');
        }
        $canonical = Sig::canonical($request->getMethod(), '/'.$request->path(), $request->query->all(), $service, $audience, $timestamp, $nonce, Sig::requestDigest($request), $context);
        if (! Sig::matches($canonical, $signature, $secrets)) {
            return $deny('invalid_signature');
        }
        if (! Cache::add('ai-nonce:'.hash('sha256', $nonce), 1, max(120, 2 * (int) ($cfg['clock_skew'] ?? 60) + 30))) {
            return $deny('replayed_request');
        }
        $decoded = Sig::decodeContext($context);
        if (! in_array($scope, (array) ($decoded['scopes'] ?? []), true)) {
            return $deny('missing_scope', 403);
        }

        return $next($request);
    }
}
