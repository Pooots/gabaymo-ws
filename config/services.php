<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    // Partner portal base URL for partner emails (defaults to FRONTEND_URL).
    'partner_url' => env('PARTNER_URL') ?: env('FRONTEND_URL', 'http://localhost:3000'),

    // Requests per minute for the named limiters in AppServiceProvider.
    'rate_limits' => [
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),
        'admin_login' => (int) env('RATE_LIMIT_ADMIN_LOGIN', 5),
        'register' => (int) env('RATE_LIMIT_REGISTER', 5),
        'password' => (int) env('RATE_LIMIT_PASSWORD', 5),
        'redeem' => (int) env('RATE_LIMIT_REDEEM', 5),
        'partner' => (int) env('RATE_LIMIT_PARTNER', 120),
        'ai' => (int) env('RATE_LIMIT_AI', 60),
        'invitation' => (int) env('RATE_LIMIT_INVITATION', 30),
        'invitation_accept' => (int) env('RATE_LIMIT_INVITATION_ACCEPT', 5),
    ],

    // Partner / staff set-up links (account_invitations).
    'invitations' => [
        'expires_hours' => (int) env('INVITE_EXPIRES_HOURS', 72),
    ],

    // The one admin and one partner DatabaseSeeder creates. Without a password
    // the account is invited and the seeder prints its set-up link.
    'seed' => [
        'admin_email' => env('SEED_ADMIN_EMAIL', 'admin@gabaymo.local'),
        'admin_name' => env('SEED_ADMIN_NAME', 'GabayMo Admin'),
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'partner_email' => env('SEED_PARTNER_EMAIL', 'partner@gabaymo.local'),
        'partner_name' => env('SEED_PARTNER_NAME', 'GabayMo Partner'),
        'partner_password' => env('SEED_PARTNER_PASSWORD'),
    ],

    // Staff sign-in lockout after repeated wrong passwords or two-factor codes.
    'admin_security' => [
        'max_attempts' => (int) env('ADMIN_LOGIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('ADMIN_LOGIN_LOCK_MINUTES', 15),
    ],

    'tickets' => [
        // Hours a partner has to reply after accepting a ticket or after a member message.
        'reply_hours' => (int) env('TICKET_REPLY_HOURS', 24),
    ],

    'subscriptions' => [
        // Renewal reminder emails, in days before the paid period ends.
        'reminder_days' => [7, 1],
    ],

    /*
    | GabayMo AI microservice. Two separate secrets, one per direction:
    |  - internal_key:  ws -> ai requests (must equal gabaymo-ai GABAYMO_AI_INTERNAL_KEY)
    |  - inbound_key:   ai -> ws requests (must equal gabaymo-ai GABAYMO_INTERNAL_KEY)
    | Neither is ever sent to a browser.
    */
    'gabaymo_ai' => [
        'url' => rtrim((string) env('GABAYMO_AI_URL', 'http://127.0.0.1:8100'), '/'),
        'internal_key' => env('GABAYMO_AI_INTERNAL_KEY'),
        'inbound_keys' => array_values(array_filter([env('GABAYMO_INTERNAL_KEY'), env('GABAYMO_INTERNAL_KEY_PREVIOUS')])),
        'service_id' => 'gabaymo-ws',
        'audience' => 'gabaymo-ai',
        'self_audience' => 'gabaymo-ws',
        'clock_skew' => (int) env('GABAYMO_SERVICE_CLOCK_SKEW', 60),
        'timeout' => (int) env('GABAYMO_AI_TIMEOUT', 30),
        'answer_timeout' => (int) env('GABAYMO_AI_ANSWER_TIMEOUT', 120),
        'connect_timeout' => 3,
        'retries' => (int) env('GABAYMO_AI_RETRIES', 2),
        'upload_max_mb' => (int) env('AI_DOCUMENT_UPLOAD_MAX_MB', 30),
    ],

    /*
    | Usage metering. Members are charged for the real tokens of each answered
    | question (their question + retrieved context in, the answer out), weighted
    | the way the model provider prices them, and see it as a percentage of
    | their plan's allowance. Allowances live on subscription_plans.
    */
    'usage' => [
        // Weighted tokens = input*input + cached_input*cached_input + output*output.
        // Ratios follow gpt-4o-mini pricing (output 4x input, cached input 50% off).
        'weights' => [
            'input' => (float) env('USAGE_WEIGHT_INPUT', 1.0),
            'cached_input' => (float) env('USAGE_WEIGHT_CACHED_INPUT', 0.5),
            'output' => (float) env('USAGE_WEIGHT_OUTPUT', 4.0),
        ],
        // Held while a question is in flight, then trued up to the real usage.
        'reserve_tokens' => (int) env('USAGE_RESERVE_TOKENS', 15000),
        // Typical weighted tokens per question; only used for "about N questions" copy.
        'tokens_per_question' => (int) env('USAGE_TOKENS_PER_QUESTION', 10000),
        // Percent-used levels that change the member's meter to warning / critical.
        'warning_percent' => 75,
        'critical_percent' => 90,
        // Holds gabaymo-ai never settled are released after this many minutes (credits:sweep).
        'reservation_ttl_minutes' => (int) env('USAGE_RESERVATION_TTL_MINUTES', 15),
    ],

    'paymongo' => [
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'env' => env('PAYMONGO_ENV', 'sandbox'),
    ],

];
