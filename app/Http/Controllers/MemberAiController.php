<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RelaysAiResponses;
use App\Services\Billing\TokenMeter;
use App\Services\GabayMoAi\AiContext;
use App\Services\GabayMoAi\GabayMoAiClient;
use App\Services\GabayMoAi\GabayMoAiUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * School-head questions. gabaymo-ws checks the plan and holds part of the
 * member's token allowance before forwarding; gabaymo-ai later finalizes it
 * with the real input/output tokens (answer sent) or releases it (no billable
 * answer). Retries with the same Idempotency-Key reuse the same hold and question.
 */
class MemberAiController extends Controller
{
    use RelaysAiResponses;

    public function __construct(private readonly GabayMoAiClient $ai, private readonly TokenMeter $credits) {}

    public function ask(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);
        $user = $request->user();
        $key = $this->idempotencyKey($request, 'ask'.$user->id);

        $reserved = $this->credits->reserve($user, $key);
        if (! $reserved['ok']) {
            $fresh = $user->fresh('subscriptionPlan');
            $usage = $this->credits->snapshot($fresh);
            $resets = $usage['resets_at'] ? ' It refreshes on '.Carbon::parse($usage['resets_at'])->timezone(config('app.timezone'))->format('F j').'.' : '';
            $message = match (true) {
                $reserved['reason'] !== 'usage_limit_reached' => 'Your membership plan is not active.',
                // What is left is held by questions still being answered.
                $usage['percent_used'] < 100 => 'Your remaining usage is held by questions still being answered. Try again in a moment.',
                default => 'You have used 100% of your plan\'s usage.'.$resets.' Upgrade to keep asking.',
            };

            return response()->json([
                'message' => $message,
                'error' => $reserved['reason'],
                'usage' => $usage,
            ], 402);
        }
        $reservation = $reserved['reservation'];

        try {
            $response = $this->ai->send('POST', 'v1/ai/chat', AiContext::member($user->loadMissing('subscriptionPlan'), $reservation->id), [], array_filter([
                'message' => $data['message'],
                'conversation_id' => $data['conversation_id'] ?? null,
            ]), $key);
        } catch (GabayMoAiUnavailable $e) {
            $this->credits->release($reservation->id, null, 'ai_unreachable');

            return $this->unavailable($e);
        }

        if (! $response->successful()) {
            // The question never reached a billable stage.
            $this->credits->release($reservation->id, null, 'ai_rejected_'.$response->status());

            return $this->relay($response);
        }

        $payload = $response->json();
        $payload['usage'] = $this->credits->snapshot($user->fresh('subscriptionPlan'));

        return response()->json($payload, $response->status());
    }

    public function memorySettings(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->memoryCall($request, 'PUT', 'v1/ai/memory/settings', ['enabled' => (bool) $data['enabled']]);
    }

    public function forgetMemory(Request $request, ?string $id = null)
    {
        return $this->memoryCall($request, 'DELETE', 'v1/ai/memory'.($id ? '/'.$id : ''), null);
    }

    /** The member is always taken from the session, so nobody can read or change another member's memory. */
    private function memoryCall(Request $request, string $method, string $path, ?array $body)
    {
        try {
            $response = $this->ai->send($method, $path, AiContext::member($request->user()), [], $body, $this->idempotencyKey($request, 'memory'.$request->user()->id));
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }

        return $this->relay($response);
    }

    /** PATCH /v1/ai/conversations/{id} {title} */
    public function renameConversation(Request $request, string $id)
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:120']]);

        return $this->memberCall($request, 'PATCH', "v1/ai/conversations/{$id}", ['title' => trim($data['title'])], 'conv');
    }

    /** DELETE /v1/ai/conversations/{id} (soft delete at gabaymo-ai; the member must own it). */
    public function deleteConversation(Request $request, string $id)
    {
        return $this->memberCall($request, 'DELETE', "v1/ai/conversations/{$id}", null, 'conv');
    }

    /** POST /v1/ai/messages/{id}/feedback {rating: up|down, reason?} */
    public function feedback(Request $request, string $id)
    {
        $data = $request->validate([
            'rating' => ['required', 'in:up,down'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->memberCall($request, 'POST', "v1/ai/messages/{$id}/feedback", array_filter([
            'rating' => $data['rating'],
            'reason' => $data['reason'] ?? null,
        ], fn ($v) => $v !== null), 'feedback');
    }

    private function memberCall(Request $request, string $method, string $path, ?array $body, string $prefix)
    {
        try {
            $response = $this->ai->send($method, $path, AiContext::member($request->user()), [], $body, $this->idempotencyKey($request, $prefix.$request->user()->id));
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }
        if ($response->status() === 204) {
            return response()->noContent();
        }

        return $this->relay($response);
    }

    public function forward(Request $request, string $path)
    {
        $allowed = [
            '#^conversations$#' => 'v1/ai/conversations',
            '#^conversations/([0-9a-f-]{36})$#' => 'v1/ai/conversations/$1',
            '#^conversations/([0-9a-f-]{36})/messages$#' => 'v1/ai/conversations/$1/messages',
            '#^questions/([0-9a-f-]{36})$#' => 'v1/ai/questions/$1',
            '#^memory$#' => 'v1/ai/memory',
        ];
        $aiPath = null;
        foreach ($allowed as $regex => $target) {
            if (preg_match($regex, $path)) {
                $aiPath = preg_replace($regex, $target, $path);
                break;
            }
        }
        if (! $aiPath) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $response = $this->ai->send('GET', $aiPath, AiContext::member($request->user()), array_filter(array_intersect_key($request->query(), array_flip(['after_id', 'page', 'per_page', 'search'])), 'is_scalar'));
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }

        return $this->relay($response);
    }
}
