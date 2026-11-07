<?php

namespace App\Services\GabayMoAi;

use App\Services\GabayMoAi\ServiceSignature as Sig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signed, bounded calls from gabaymo-ws to gabaymo-ai.
 *
 * Every call carries a short-lived HMAC assertion: service id, audience,
 * timestamp, single-use nonce, body digest and the actor context that
 * gabaymo-ws resolved from the session (never from browser input).
 */
class GabayMoAiClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.gabaymo_ai.url') !== '' && (string) config('services.gabaymo_ai.internal_key') !== '';
    }

    /**
     * @param  array<string, mixed>  $context  actor context (actor_type, user_id, partner_id, scopes, geo, ...)
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    public function send(string $method, string $path, array $context, array $query = [], ?array $json = null, ?string $idempotencyKey = null, ?int $timeout = null): Response
    {
        $method = strtoupper($method);
        $path = '/api/'.ltrim($path, '/');
        $body = $json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url = $this->url($path, $query);

        return $this->dispatch($method, $url, $path, $query, hash('sha256', $body), $context, $idempotencyKey, $timeout, function ($request) use ($method, $url, $body, $json) {
            if ($json !== null) {
                $request = $request->withBody($body, 'application/json');
            }

            return $request->send($method, $url);
        });
    }

    /** Multipart upload: one file plus flat string fields. */
    public function upload(string $path, array $context, UploadedFile $file, array $fields, ?string $idempotencyKey = null): Response
    {
        $path = '/api/'.ltrim($path, '/');
        $fields = array_map('strval', $fields);
        $digest = Sig::multipartDigest($fields, ['file' => (string) hash_file('sha256', $file->getRealPath())]);
        $url = $this->url($path, []);

        return $this->dispatch('POST', $url, $path, [], $digest, $context, $idempotencyKey, (int) config('services.gabaymo_ai.timeout', 30) + 30, function ($request) use ($url, $file, $fields) {
            return $request
                ->attach('file', fopen((string) $file->getRealPath(), 'r'), $this->safeName($file->getClientOriginalName()))
                ->post($url, $fields);
        }, retry: false);
    }

    /** Streaming GET (file downloads). */
    public function stream(string $path, array $context, array $query = []): Response
    {
        $path = '/api/'.ltrim($path, '/');
        $url = $this->url($path, $query);

        return $this->dispatch('GET', $url, $path, $query, hash('sha256', ''), $context, null, 120, fn ($request) => $request->withOptions(['stream' => true])->get($url));
    }

    private function dispatch(string $method, string $url, string $path, array $query, string $digest, array $context, ?string $idempotencyKey, ?int $timeout, callable $send, bool $retry = true): Response
    {
        if (! $this->isConfigured()) {
            throw new GabayMoAiUnavailable('The AI service is not configured.');
        }
        $idempotencyKey ??= in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'ws-'.Str::uuid() : null;
        $requestId = (string) (request()?->header('X-Request-Id') ?: Str::uuid());
        $attempts = $retry ? max(1, (int) config('services.gabaymo_ai.retries', 2) + 1) : 1;

        for ($attempt = 1; ; $attempt++) {
            // Fresh timestamp/nonce per attempt; the Idempotency-Key stays the same.
            $headers = $this->headers($method, $path, $query, $digest, $context, $requestId);
            if ($idempotencyKey) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            try {
                $request = Http::withHeaders($headers)
                    ->accept('application/json')
                    ->timeout($timeout ?? (int) config('services.gabaymo_ai.timeout', 30))
                    ->connectTimeout((int) config('services.gabaymo_ai.connect_timeout', 3));
                $response = $send($request);
            } catch (ConnectionException $e) {
                if ($attempt < $attempts) {
                    usleep(200_000 * (2 ** ($attempt - 1)));

                    continue;
                }
                throw new GabayMoAiUnavailable('The AI service could not be reached.', $e);
            } catch (Throwable $e) {
                throw new GabayMoAiUnavailable('The AI service request failed.', $e);
            }

            // Retry gateway errors only when it is safe: idempotent mutation or GET.
            $retryable = in_array($response->status(), [502, 503, 504], true) && $attempt < $attempts && ($method === 'GET' || $idempotencyKey !== null);
            if ($retryable) {
                usleep(200_000 * (2 ** ($attempt - 1)));

                continue;
            }

            return $response;
        }
    }

    private function headers(string $method, string $path, array $query, string $digest, array $context, string $requestId): array
    {
        $service = (string) config('services.gabaymo_ai.service_id', 'gabaymo-ws');
        $audience = (string) config('services.gabaymo_ai.audience', 'gabaymo-ai');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $contextHeader = Sig::encodeContext($context);
        $canonical = Sig::canonical($method, $path, $query, $service, $audience, $timestamp, $nonce, $digest, $contextHeader);

        return [
            Sig::H_SERVICE => $service,
            Sig::H_AUDIENCE => $audience,
            Sig::H_TIMESTAMP => $timestamp,
            Sig::H_NONCE => $nonce,
            Sig::H_CONTEXT => $contextHeader,
            Sig::H_SIGNATURE => Sig::sign($canonical, (string) config('services.gabaymo_ai.internal_key')),
            Sig::H_REQUEST_ID => preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $requestId) ? $requestId : (string) Str::uuid(),
        ];
    }

    private function url(string $path, array $query): string
    {
        $url = rtrim((string) config('services.gabaymo_ai.url'), '/').$path;
        if ($query !== []) {
            // Same encoding as the canonical query so signatures match.
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr(preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name) ?: 'document', 0, 200);
    }
}
