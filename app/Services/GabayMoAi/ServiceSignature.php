<?php

namespace App\Services\GabayMoAi;

use Illuminate\Http\Request;

/**
 * Canonical HMAC-SHA256 request signing shared by gabaymo-ws and gabaymo-ai.
 * gabaymo-ai carries an identical copy (App\Services\Auth\ServiceSignature);
 * keep both in sync. See docs/SERVICE_AUTH.md.
 */
final class ServiceSignature
{
    public const SCHEME = 'GABAYMO-HMAC-SHA256-V1';

    public const H_SERVICE = 'X-GabayMo-Service';

    public const H_AUDIENCE = 'X-GabayMo-Audience';

    public const H_TIMESTAMP = 'X-GabayMo-Timestamp';

    public const H_NONCE = 'X-GabayMo-Nonce';

    public const H_CONTEXT = 'X-GabayMo-Context';

    public const H_SIGNATURE = 'X-GabayMo-Signature';

    public const H_REQUEST_ID = 'X-Request-Id';

    /**
     * @param  array<string, mixed>  $query
     */
    public static function canonical(
        string $method,
        string $path,
        array $query,
        string $service,
        string $audience,
        string $timestamp,
        string $nonce,
        string $bodyDigest,
        string $contextHeader,
    ): string {
        return implode("\n", [
            self::SCHEME,
            strtoupper($method),
            '/'.ltrim($path, '/'),
            self::canonicalQuery($query),
            $service,
            $audience,
            $timestamp,
            $nonce,
            $bodyDigest,
            hash('sha256', $contextHeader),
        ]);
    }

    public static function sign(string $canonical, string $secret): string
    {
        return 'v1='.hash_hmac('sha256', $canonical, $secret);
    }

    /** Constant-time comparison against every accepted secret (rotation). */
    public static function matches(string $canonical, string $provided, array $secrets): bool
    {
        $ok = false;
        foreach ($secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }
            // Evaluate every secret so timing does not reveal which one matched.
            $ok = hash_equals(self::sign($canonical, $secret), $provided) || $ok;
        }

        return $ok;
    }

    /** @param  array<string, mixed>  $query */
    public static function canonicalQuery(array $query): string
    {
        $flat = [];
        $walk = function (array $items, string $prefix = '') use (&$walk, &$flat): void {
            foreach ($items as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
                if (is_array($value)) {
                    $walk($value, $name);
                } else {
                    $flat[] = rawurlencode($name).'='.rawurlencode((string) $value);
                }
            }
        };
        $walk($query);
        sort($flat, SORT_STRING);

        return implode('&', $flat);
    }

    /**
     * Body digest. JSON/raw bodies hash the exact bytes. Multipart bodies hash
     * a canonical list of fields plus the SHA-256 of each uploaded file, so the
     * signer does not need to know the multipart boundary.
     *
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $fileHashes  field name => sha256 of file bytes
     */
    public static function multipartDigest(array $fields, array $fileHashes): string
    {
        ksort($fields, SORT_STRING);
        ksort($fileHashes, SORT_STRING);
        $lines = [];
        foreach ($fields as $name => $value) {
            $lines[] = $name.'='.$value;
        }
        foreach ($fileHashes as $name => $hash) {
            $lines[] = $name.'=@'.$hash;
        }

        return hash('sha256', implode("\n", $lines));
    }

    public static function requestDigest(Request $request): string
    {
        $type = strtolower((string) $request->header('Content-Type'));
        if (str_starts_with($type, 'multipart/form-data')) {
            $fields = [];
            foreach ($request->request->all() as $name => $value) {
                if (! is_string($value)) {
                    // Only flat string fields are allowed in signed multipart requests.
                    return 'invalid-multipart';
                }
                $fields[(string) $name] = $value;
            }
            $files = [];
            foreach ($request->files->all() as $name => $file) {
                if (! $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || ! $file->isValid()) {
                    return 'invalid-multipart';
                }
                $files[(string) $name] = (string) hash_file('sha256', $file->getRealPath());
            }

            return self::multipartDigest($fields, $files);
        }

        return hash('sha256', (string) $request->getContent());
    }

    public static function encodeContext(array $context): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($context, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    public static function decodeContext(string $header): ?array
    {
        $json = base64_decode(strtr($header, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
