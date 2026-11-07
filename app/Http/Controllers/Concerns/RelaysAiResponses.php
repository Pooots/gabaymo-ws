<?php

namespace App\Http\Controllers\Concerns;

use App\Services\GabayMoAi\GabayMoAiUnavailable;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RelaysAiResponses
{
    /** Pass the AI service's JSON through, but never let its 401 log the browser out. */
    protected function relay(Response $response): JsonResponse
    {
        $status = $response->status();
        $body = $response->json();
        if (! is_array($body)) {
            $body = ['message' => 'The AI service returned an unexpected response.'];
            $status = $status >= 400 ? $status : 502;
        }
        if ($status === 401) {
            report(new \RuntimeException('gabaymo-ai rejected the service signature: '.($body['error'] ?? 'unknown')));

            return response()->json(['message' => 'The AI service could not verify this server. Check the shared service keys.', 'error' => 'ai_service_auth'], 502);
        }

        return response()->json($body, $status);
    }

    protected function unavailable(GabayMoAiUnavailable $e): JsonResponse
    {
        report($e);

        return response()->json(['message' => 'The AI service is temporarily unavailable. Please try again shortly.', 'error' => 'ai_unavailable'], 503);
    }

    /** Browser-supplied Idempotency-Key, sanitized and namespaced; otherwise a fresh one. */
    protected function idempotencyKey(Request $request, string $prefix): string
    {
        $key = (string) $request->header('Idempotency-Key', '');

        return preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key) ? $prefix.':'.$key : $prefix.':'.\Illuminate\Support\Str::uuid();
    }
}
