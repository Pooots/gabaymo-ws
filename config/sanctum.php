<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these hosts (matched against the Origin / Referer header)
    | receive session cookies and CSRF protection. List every frontend host,
    | with the port when it is not 80/443. No scheme.
    |
    | Production: gabaymo.com,partner.gabaymo.com,admin.gabaymo.com
    |
    */

    'stateful' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:3000,127.0.0.1:8000,localhost:8000,::1'
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Only used by the "auth:sanctum" middleware. Gabaymo's own middleware
    | (member / partner / admin) reads the "web" and "admin" guards directly.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Applies to personal access tokens only (not used by the SPA). Session
    | lifetime is controlled by SESSION_LIFETIME.
    |
    */

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ],

];
