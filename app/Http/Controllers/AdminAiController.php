<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RelaysAiResponses;
use App\Models\AdminUser;
use App\Services\GabayMoAi\AiContext;
use App\Services\GabayMoAi\GabayMoAiClient;
use App\Services\GabayMoAi\GabayMoAiUnavailable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated gateway from the admin SPA to gabaymo-ai.
 *
 * Only routes in ROUTES are forwarded. Each requires an admin module
 * permission here, and gabaymo-ai independently checks the scopes derived
 * from the same role. Partner/region filters an admin chooses are passed as
 * query/body filters, never as identity.
 */
class AdminAiController extends Controller
{
    use RelaysAiResponses;

    /** [method, ws path regex (after admin/ai/), modules (any), audit action|null] */
    private const ROUTES = [
        ['GET', '#^knowledge/documents$#', ['knowledge-library', 'source-review', 'knowledge-search'], null],
        ['POST', '#^knowledge/documents$#', ['knowledge-library'], 'ai.knowledge.upload'],
        ['GET', '#^knowledge/documents/[0-9a-f-]{36}$#', ['knowledge-library', 'source-review', 'knowledge-search', 'ai-review'], null],
        ['GET', '#^knowledge/documents/[0-9a-f-]{36}/(pages|chunks|versions)$#', ['knowledge-library', 'source-review', 'knowledge-search', 'ai-review'], null],
        ['POST', '#^knowledge/documents/[0-9a-f-]{36}/versions$#', ['knowledge-library'], 'ai.knowledge.new_version'],
        ['PATCH', '#^knowledge/documents/[0-9a-f-]{36}$#', ['knowledge-library', 'source-review'], 'ai.knowledge.metadata'],
        ['PUT', '#^knowledge/documents/[0-9a-f-]{36}/pages/\d+$#', ['knowledge-library', 'source-review'], 'ai.knowledge.text_correction'],
        ['PUT', '#^knowledge/documents/[0-9a-f-]{36}/applicability$#', ['knowledge-library', 'source-review'], 'ai.knowledge.applicability'],
        ['POST', '#^knowledge/documents/[0-9a-f-]{36}/relations$#', ['knowledge-library', 'source-review'], 'ai.knowledge.relation'],
        ['DELETE', '#^knowledge/documents/[0-9a-f-]{36}/relations/\d+$#', ['knowledge-library', 'source-review'], 'ai.knowledge.relation_removed'],
        ['POST', '#^knowledge/documents/[0-9a-f-]{36}/(reprocess|submit|archive)$#', ['knowledge-library'], 'ai.knowledge.action'],
        ['POST', '#^knowledge/documents/[0-9a-f-]{36}/reprocess$#', ['source-review'], 'ai.knowledge.reextract'],
        ['POST', '#^knowledge/documents/[0-9a-f-]{36}/retry-publish$#', ['source-review'], 'ai.knowledge.retry_publish'],
        ['GET', '#^knowledge/reviews$#', ['source-review'], null],
        ['GET', '#^knowledge/reviews/[0-9a-f-]{36}$#', ['source-review'], null],
        ['POST', '#^knowledge/reviews/[0-9a-f-]{36}/(approve|return|reject)$#', ['source-review'], 'ai.source_review'],
        ['POST', '#^knowledge/search$#', ['knowledge-search'], null],
        ['POST', '#^knowledge/answer$#', ['knowledge-search'], 'ai.knowledge.answer_test'],
        ['GET', '#^knowledge/(search-history|usage)$#', ['knowledge-search'], null],
        ['GET', '#^instructions(/effective)?$#', ['service-instructions'], null],
        ['GET', '#^instructions/\d+/versions$#', ['service-instructions'], null],
        ['POST', '#^instructions(/reorder)?$#', ['service-instructions'], 'ai.instruction'],
        ['PATCH', '#^instructions/\d+$#', ['service-instructions'], 'ai.instruction'],
        ['DELETE', '#^instructions/\d+$#', ['service-instructions'], 'ai.instruction.archived'],
        ['GET', '#^reviews$#', ['ai-review'], null],
        ['GET', '#^reviews/[0-9a-f-]{36}$#', ['ai-review'], null],
        ['POST', '#^reviews/[0-9a-f-]{36}/(approve|reject|edit|handoff|regenerate|assign)$#', ['ai-review'], 'ai.answer_review'],
        ['GET', '#^geo/regions$#', ['knowledge-library', 'source-review', 'knowledge-search', 'ai-review', 'service-instructions'], null],
        ['GET', '#^health$#', ['overview', 'knowledge-library'], null],
        ['GET', '#^feedback$#', ['feedback', 'ai-review'], null],
        ['GET', '#^analytics$#', ['analytics', 'ai-review'], null],
    ];

    public function __construct(private readonly GabayMoAiClient $ai) {}

    public function forward(Request $request, string $path)
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $method = $request->getMethod();
        $route = $this->match($method, $path);
        if (! $route) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        if (! $admin->role || ! $admin->role->allowsAny($route[2])) {
            return response()->json(['message' => 'You do not have access to this module.'], 403);
        }

        $context = AiContext::admin($admin->loadMissing('role'));
        $aiPath = $this->aiPath($path);
        $key = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $this->idempotencyKey($request, 'admin'.$admin->id) : null;

        try {
            $isNewDocument = $method === 'POST' && $path === 'knowledge/documents';
            $isNewVersion = $method === 'POST' && (bool) preg_match('#^knowledge/documents/[0-9a-f-]{36}/versions$#', $path);
            if ($isNewDocument || $isNewVersion) {
                $request->validate([
                    // Light pre-check only; gabaymo-ai verifies the real MIME type from the bytes.
                    'file' => ['required', 'file', 'max:'.((int) config('services.gabaymo_ai.upload_max_mb', 30) * 1024), 'extensions:pdf,docx,txt,md,markdown'],
                    'metadata' => [$isNewDocument ? 'required' : 'nullable', 'string', 'max:20000'],
                    'note' => ['nullable', 'string', 'max:1000'],
                ]);
                $fields = array_filter(['metadata' => $request->input('metadata'), 'note' => $request->input('note')], fn ($v) => $v !== null && $v !== '');
                $response = $this->ai->upload($aiPath, $context, $request->file('file'), $fields, $key);
            } else {
                $timeout = $path === 'knowledge/answer' ? (int) config('services.gabaymo_ai.answer_timeout', 120) : null;
                $json = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $request->json()->all() : null;
                $response = $this->ai->send($method, $aiPath, $context, $method === 'GET' ? $this->query($request) : [], $json, $key, $timeout);
            }
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }

        if ($route[3] && $response->successful()) {
            AdminOverviewController::record($request, $route[3], $this->summary($route[3], $path, $response->json()));
        }

        return $this->relay($response);
    }

    public function downloadDocument(Request $request, string $id)
    {
        $admin = $request->user();
        if (! $admin->role || ! $admin->role->allowsAny(['knowledge-library', 'source-review'])) {
            return response()->json(['message' => 'You do not have access to this module.'], 403);
        }
        if (! preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        try {
            return $this->download("v1/knowledge/documents/{$id}/download", AiContext::admin($admin->loadMissing('role')), $this->query($request));
        } catch (GabayMoAiUnavailable $e) {
            return $this->unavailable($e);
        }
    }

    private function download(string $aiPath, array $context, array $query)
    {
        $response = $this->ai->stream($aiPath, $context, array_intersect_key($query, ['version_id' => 1]));
        if (! $response->successful()) {
            return $this->relay($response);
        }
        $stream = $response->toPsrResponse()->getBody();

        return new StreamedResponse(function () use ($stream) {
            while (! $stream->eof()) {
                echo $stream->read(65536);
                flush();
            }
        }, 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function match(string $method, string $path): ?array
    {
        foreach (self::ROUTES as $route) {
            if ($route[0] === $method && preg_match($route[1], $path)) {
                return $route;
            }
        }

        return null;
    }

    private function aiPath(string $path): string
    {
        return match (true) {
            $path === 'health' => 'health/dependencies',
            str_starts_with($path, 'knowledge/') => 'v1/'.$path,
            str_starts_with($path, 'geo/') => 'v1/'.$path,
            default => 'v1/ai/'.$path,
        };
    }

    /** Only flat scalar / list-of-scalar query parameters are forwarded. */
    private function query(Request $request): array
    {
        $out = [];
        foreach ($request->query() as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]{1,40}$/', $key) && (is_scalar($value) || (is_array($value) && array_is_list($value)))) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function summary(string $action, string $path, mixed $json): string
    {
        $title = is_array($json) ? (data_get($json, 'data.title') ?? data_get($json, 'data.metadata.title') ?? data_get($json, 'data.document.title')) : null;

        return trim(match ($action) {
            'ai.knowledge.upload' => 'Uploaded knowledge document',
            'ai.source_review' => 'Source review: '.basename($path),
            'ai.answer_review' => 'AI answer review: '.basename($path),
            'ai.instruction', 'ai.instruction.archived' => 'Changed service instructions',
            default => ucfirst(str_replace(['ai.', '.', '_'], ['', ' ', ' '], $action)),
        }.($title ? ' — '.$title : ''));
    }
}
