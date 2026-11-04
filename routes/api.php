<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminMemberController;
use App\Http\Controllers\MemberAuthController;
use App\Http\Controllers\MemberPaymentController;
use App\Http\Controllers\MemberTicketController;
use App\Http\Controllers\AdminInstructionController;
use App\Http\Controllers\AdminKnowledgeController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\AdminPartnerController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\AdminSubscriptionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'ok' => true,
        'type' => 'api',
        'service' => 'gabaymo-api',
        'name' => 'Gabaymo',
        'version' => '1.0.0',
        'description' => 'Gabaymo API',
        'health' => url('/api/health'),
        'v1' => url('/api/v1'),
    ]);
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (\Throwable) {
        $dbStatus = 'disconnected';
    }

    $ok = $dbStatus === 'connected';

    return response()->json([
        'ok' => $ok,
        'service' => 'gabaymo-api',
        'status' => $ok ? 'ok' : 'degraded',
        'app' => config('app.name'),
        'env' => config('app.env'),
        'database' => $dbStatus,
        'timestamp' => now()->toIso8601String(),
        'php' => PHP_VERSION,
    ], $ok ? 200 : 503);
});

Route::prefix('v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'ok' => true,
            'service' => 'gabaymo-api',
            'version' => 'v1',
            'health' => url('/api/health'),
        ]);
    });

    Route::post('register', [MemberAuthController::class, 'register']);
    Route::post('login', [MemberAuthController::class, 'login']);

    Route::middleware('member')->group(function () {
        Route::get('me', [MemberAuthController::class, 'me']);
        Route::put('me', [MemberAuthController::class, 'update']);
        Route::post('logout', [MemberAuthController::class, 'logout']);
        Route::get('tickets', [MemberTicketController::class, 'index']);
        Route::post('tickets', [MemberTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [MemberTicketController::class, 'show']);
        Route::post('tickets/{ticket}/messages', [MemberTicketController::class, 'message']);
        Route::post('payments/checkout', [MemberPaymentController::class, 'store']);
        Route::post('payments/confirm', [MemberPaymentController::class, 'confirm']);
    });

    Route::middleware('partner')->group(function () {
        Route::get('partner/me', [PartnerController::class, 'me']);
        Route::get('partner/dashboard', [PartnerController::class, 'dashboard']);
        Route::get('partner/instructions', [PartnerController::class, 'instructions']);
        Route::get('partner/knowledge', [PartnerController::class, 'knowledge']);
        Route::get('partner/tickets', [PartnerController::class, 'tickets']);
        Route::get('partner/accepted', [PartnerController::class, 'accepted']);
        Route::post('partner/tickets/{ticket}/accept', [PartnerController::class, 'accept']);
        Route::get('partner/tickets/{ticket}/messages', [PartnerController::class, 'messages']);
        Route::post('partner/tickets/{ticket}/messages', [PartnerController::class, 'storeMessage']);
        Route::put('partner/profile', [PartnerController::class, 'update']);
        Route::post('partner/logout', [PartnerController::class, 'logout']);
    });

    Route::post('paymongo/webhook', [MemberPaymentController::class, 'webhook']);
    Route::post('admin/login', [AdminAuthController::class, 'login']);
    Route::get('plans', [AdminSubscriptionController::class, 'catalog']);

    Route::middleware('admin')->group(function () {
        Route::get('admin/me', [AdminAuthController::class, 'me']);
        Route::post('admin/logout', [AdminAuthController::class, 'logout']);
        Route::get('admin/overview', [AdminOverviewController::class, 'show']);
        Route::get('admin/audit', [AdminOverviewController::class, 'audit']);
        Route::get('admin/subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::post('admin/subscriptions', [AdminSubscriptionController::class, 'store']);
        Route::put('admin/subscriptions/{subscription}', [AdminSubscriptionController::class, 'update']);
        Route::get('admin/knowledge-documents', [AdminKnowledgeController::class, 'index']);
        Route::post('admin/knowledge-documents', [AdminKnowledgeController::class, 'store']);
        Route::put('admin/knowledge-documents/{knowledgeDocument}', [AdminKnowledgeController::class, 'update']);
        Route::put('admin/knowledge-documents/{knowledgeDocument}/review', [AdminKnowledgeController::class, 'review']);
        Route::get('admin/members', [AdminMemberController::class, 'index']);
        Route::get('admin/partners', [AdminPartnerController::class, 'index']);
        Route::post('admin/partners', [AdminPartnerController::class, 'store']);
        Route::get('admin/roles', [AdminRoleController::class, 'index']);
        Route::post('admin/roles', [AdminRoleController::class, 'store']);
        Route::put('admin/roles/{staffRole}', [AdminRoleController::class, 'update']);
        Route::delete('admin/roles/{staffRole}', [AdminRoleController::class, 'destroy']);
        Route::post('admin/roles/{staffRole}/accounts', [AdminRoleController::class, 'storeAccount']);
        Route::get('admin/service-instructions', [AdminInstructionController::class, 'index']);
        Route::post('admin/service-instructions', [AdminInstructionController::class, 'store']);
        Route::put('admin/service-instructions/{serviceInstruction}', [AdminInstructionController::class, 'update']);
    });
});
