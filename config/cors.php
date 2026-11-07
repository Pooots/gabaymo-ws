<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Supports:
    | - Local: Vite on :3000 → php artisan serve
    | - Split: hosted frontend → this API
    | - Same-origin: SPA + API on one host (APP_URL)
    |
    */

    'paths' => ['api', 'api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Exact origins only: APP_URL, FRONTEND_URL and CORS_ALLOWED_ORIGINS (comma list).
    // The Vite/artisan localhost origins are added only when APP_ENV is local or testing.
    'allowed_origins' => (static function (): array {
        $normalize = static function (?string $origin): ?string {
            $origin = rtrim(trim((string) $origin), '/');

            return $origin !== '' && $origin !== '*' ? $origin : null;
        };

        $origins = [
            $normalize(env('APP_URL', 'http://127.0.0.1:8000')),
            $normalize(env('FRONTEND_URL')),
        ];

        if (in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)) {
            array_push(
                $origins,
                'http://localhost:3000',
                'http://127.0.0.1:3000',
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:8000',
                'http://127.0.0.1:8000',
            );
        }

        foreach (explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')) as $extra) {
            $origins[] = $normalize($extra);
        }

        return array_values(array_unique(array_filter($origins)));
    })(),

    // Optional full regexes, comma-separated, e.g. a preview domain you own:
    // CORS_ALLOWED_ORIGIN_PATTERNS="#^https://[a-z0-9-]+\.preview\.gabaymo\.com$#"
    // There is deliberately no default wildcard (such as *.vercel.app, which anyone can deploy to).
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    // Required for cookie (Sanctum SPA) authentication.
    'supports_credentials' => true,

];
