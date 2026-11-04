<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $spa = public_path('index.html');

    if (is_file($spa)) {
        return response()->file($spa);
    }

    return response()->json([
        'type' => 'api',
        'name' => 'Gabaymo',
        'version' => '1.0.0',
        'description' => 'Gabaymo API',
        'api' => url('/api'),
        'health' => url('/api/health'),
    ]);
});

// SPA deep links when a frontend build is in public/
Route::get('/{any}', function () {
    $spa = public_path('index.html');

    if (is_file($spa)) {
        return response()->file($spa);
    }

    abort(404);
})->where('any', '^(?!api(?:/|$)|up$|sanctum(?:/|$)|storage(?:/|$)|broadcasting(?:/|$)).*');
