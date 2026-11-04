<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run first so preflight OPTIONS and error responses get CORS headers
        // (needed for the Vite frontend → this API).
        $middleware->prepend(HandleCors::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\AuthenticateAdmin::class,
            'member' => \App\Http\Middleware\AuthenticateMember::class,
            'partner' => \App\Http\Middleware\AuthenticatePartner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'Authentication required',
                ], 401);
            }

            return null;
        });
    })->create();
