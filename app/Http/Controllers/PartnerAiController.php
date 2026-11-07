<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RelaysAiResponses;
use App\Services\GabayMoAi\AiContext;
use App\Services\GabayMoAi\GabayMoAiClient;
use App\Services\GabayMoAi\GabayMoAiUnavailable;
use Illuminate\Http\Request;

/**
 * Partner portal access to gabaymo-ai: the partner's own Service
 * Instructions (plus read-only global rules) and the approved library.
 * The partner id always comes from the session.
 */
class PartnerAiController extends Controller
{
    use RelaysAiResponses;

    private const ROUTES = [
        ['GET', '#^instructions$#', 'v1/ai/instructions'],
        ['GET', '#^instructions/effective$#', 'v1/ai/instructions/effective'],
        ['GET', '#^instructions/(\d+)/versions$#', 'v1/ai/instructions/$1/versions'],
        ['POST', '#^instructions$#', 'v1/ai/instructions'],
        ['POST', '#^instructions/reorder$#', 'v1/ai/instructions/reorder'],
        ['PATCH', '#^instructions/(\d+)$#', 'v1/ai/instructions/$1'],
        ['DELETE', '#^instructions/(\d+)$#', 'v1/ai/instructions/$1'],
        ['GET', '#^library$#', 'v1/knowledge/library'],
        ['GET', '#^library/([0-9a-f-]{36})$#', 'v1/knowledge/library/$1'],
        // Train GabayMo: questions from published passages, the partner's answers, and their voice.
        ['POST', '#^training/questions$#', 'v1/ai/training/questions'],
        ['GET', '#^training/answers$#', 'v1/ai/training/answers'],
        ['POST', '#^training/answers$#', 'v1/ai/training/answers'],
        ['PATCH', '#^training/answers/([0-9a-f-]{36})$#', 'v1/ai/training/answers/$1'],
        ['DELETE', '#^training/answers/([0-9a-f-]{36})$#', 'v1/ai/training/answers/$1'],
        ['GET', '#^training/voice$#', 'v1/ai/training/voice'],
        ['PATCH', '#^training/voice$#', 'v1/ai/training/voice'],
        ['POST', '#^training/voice/rebuild$#', 'v1/ai/training/voice/rebuild'],
    ];

    /** Calls that wait for the model (writing a question, describing the voice). */
    private const SLOW = ['v1/ai/training/questions', 'v1/ai/training/voice/rebuild'];

    public function __construct(private readonly GabayMoAiClient $ai) {}

    public function forward(Request $request, string $path)
    {
        $method = $request->getMethod();
        $aiPath = null;
        foreach (self::ROUTES as [$m, $regex, $target]) {
            if ($m === $method && preg_match($regex, $path)) {
                $aiPath = preg_replace($regex, $target, $path);
                break;
            }
        }
        if (! $aiPath) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $json = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $request->json()->all() : null;
        if (is_array($json) && in_array($aiPath, ['v1/ai/instructions', 'v1/ai/instructions/reorder'], true)) {
            // Partners can only write partner-scoped rules; gabaymo-ai pins the partner id as well.
            $json['scope'] = 'partner';
            unset($json['partner_id'], $json['is_mandatory']);
        }
        if (is_array($json)) {
            unset($json['is_mandatory']);
        }

        try {
            $response = $this->ai->send(
                $method,
                $aiPath,
                AiContext::partner($request->user()),
                $method === 'GET' ? array_intersect_key($request->query(), array_flip(['q', 'scope', 'per_page', 'page'])) : [],
                $json,
                $json !== null ? $this->idempotencyKey($request, 'partner'.$request->user()->id) : null,
                in_array($aiPath, self::SLOW, true) ? (int) config('services.gabaymo_ai.answer_timeout', 120) : null,
            );
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }

        if ($method !== 'GET' && $response->successful() && str_starts_with($aiPath, 'v1/ai/instructions')) {
            AdminOverviewController::record($request, 'ai.partner_instruction', 'Partner changed their service instructions');
        }

        return $this->relay($response);
    }
}
