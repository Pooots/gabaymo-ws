<?php

use App\Http\Controllers\AdminAccessKeyController;
use App\Http\Controllers\AdminAiController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminInstructionController;
use App\Http\Controllers\AdminKnowledgeController;
use App\Http\Controllers\AdminMemberController;
use App\Http\Controllers\AdminOrganizationController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\AdminPartnerController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminReferralCodeController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminSubscriptionController;
use App\Http\Controllers\AdminTicketController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\InternalAiController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberAccountController;
use App\Http\Controllers\MemberAiController;
use App\Http\Controllers\MemberAuthController;
use App\Http\Controllers\MemberPaymentController;
use App\Http\Controllers\MemberTicketController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartnerAiController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ReminderController;
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
    } catch (Throwable) {
        $dbStatus = 'disconnected';
    }

    $ok = $dbStatus === 'connected';

    // Public: no environment, version or host details.
    return response()->json([
        'ok' => $ok,
        'service' => 'gabaymo-api',
        'status' => $ok ? 'ok' : 'degraded',
        'database' => $dbStatus,
        'timestamp' => now()->toIso8601String(),
    ], $ok ? 200 : 503);
})->middleware('throttle:60,1');

Route::prefix('v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'ok' => true,
            'service' => 'gabaymo-api',
            'version' => 'v1',
            'health' => url('/api/health'),
        ]);
    });

    Route::post('register', [MemberAuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [MemberAuthController::class, 'login'])->middleware('throttle:login');
    Route::get('session', [MemberAuthController::class, 'session']);
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');
    // Partner / staff set-up links emailed by staff (see InvitationController).
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:invitation');
    Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
    // Signed link from the verification email (opened in the browser, no session needed).
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')->middleware('throttle:20,1')->name('verification.verify');

    Route::middleware('member')->group(function () {
        Route::get('me', [MemberAuthController::class, 'me']);
        Route::put('me', [MemberAuthController::class, 'update']);
        Route::delete('me', [MemberAccountController::class, 'destroy'])->middleware('throttle:password');
        Route::put('me/password', [MemberAccountController::class, 'password'])->middleware('throttle:password');
        Route::get('me/export', [MemberAccountController::class, 'export'])->middleware('throttle:5,1');
        Route::post('logout', [MemberAuthController::class, 'logout']);
        Route::post('logout-all', [MemberAccountController::class, 'logoutAll']);
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1');
        Route::get('tickets', [MemberTicketController::class, 'index']);
        Route::post('tickets', [MemberTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [MemberTicketController::class, 'show']);
        Route::post('tickets/{ticket}/messages', [MemberTicketController::class, 'message']);
        Route::post('tickets/{ticket}/close', [MemberTicketController::class, 'close']);
        Route::get('payments', [MemberPaymentController::class, 'index']);
        Route::post('payments/checkout', [MemberPaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::post('payments/confirm', [MemberPaymentController::class, 'confirm']);
        Route::post('subscription/cancel', [MemberAccountController::class, 'cancel']);
        Route::post('subscription/resume', [MemberAccountController::class, 'resume']);
        Route::post('redeem', [MemberAccountController::class, 'redeem'])->middleware('throttle:redeem');
        Route::get('usage', [MemberAuthController::class, 'usage']);
        Route::get('reminders', [ReminderController::class, 'index']);
        Route::post('reminders', [ReminderController::class, 'store']);
        Route::patch('reminders/{reminder}', [ReminderController::class, 'update'])->whereNumber('reminder');
        Route::delete('reminders/{reminder}', [ReminderController::class, 'destroy'])->whereNumber('reminder');
        // GabayMo AI: ask (holds part of the token allowance) and read own conversations.
        Route::post('ai/ask', [MemberAiController::class, 'ask'])->middleware('throttle:20,1');
        Route::middleware('throttle:ai')->group(function () {
            Route::patch('ai/conversations/{id}', [MemberAiController::class, 'renameConversation'])->whereUuid('id');
            Route::delete('ai/conversations/{id}', [MemberAiController::class, 'deleteConversation'])->whereUuid('id');
            Route::post('ai/messages/{id}/feedback', [MemberAiController::class, 'feedback'])->whereNumber('id');
        });
        // What GabayMo remembers about the member: list (GET via forward), switch on/off, delete one, clear all.
        Route::put('ai/memory/settings', [MemberAiController::class, 'memorySettings']);
        Route::delete('ai/memory/{id?}', [MemberAiController::class, 'forgetMemory'])->whereUuid('id');
        Route::get('ai/{path}', [MemberAiController::class, 'forward'])->where('path', '.*')->middleware('throttle:ai');
    });

    // Shared by members and partners: in-app notifications + Reverb channel auth.
    Route::middleware('notifiable')->group(function () {
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification');
        Route::post('broadcasting/auth', [NotificationController::class, 'broadcastingAuth']);
    });

    Route::middleware(['partner', 'throttle:partner'])->group(function () {
        Route::get('partner/me', [PartnerController::class, 'me']);
        Route::put('partner/password', [MemberAccountController::class, 'password'])->middleware('throttle:password');
        Route::get('partner/dashboard', [PartnerController::class, 'dashboard']);
        Route::get('partner/instructions', [PartnerController::class, 'instructions']);
        Route::get('partner/knowledge', [PartnerController::class, 'knowledge']);
        Route::get('partner/tickets', [PartnerController::class, 'tickets']);
        Route::get('partner/accepted', [PartnerController::class, 'accepted']);
        Route::post('partner/tickets/{ticket}/accept', [PartnerController::class, 'accept']);
        Route::get('partner/tickets/{ticket}/messages', [PartnerController::class, 'messages']);
        Route::post('partner/tickets/{ticket}/messages', [PartnerController::class, 'storeMessage']);
        Route::post('partner/tickets/{ticket}/close', [PartnerController::class, 'close']);
        Route::put('partner/profile', [PartnerController::class, 'update']);
        Route::post('partner/logout', [PartnerController::class, 'logout']);
        // GabayMo AI: the partner's own service instructions and the approved library.
        Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'partner/ai/{path}', [PartnerAiController::class, 'forward'])->where('path', '.*')->middleware('throttle:ai');
    });

    // Signed calls from gabaymo-ai (never from browsers).
    Route::prefix('internal/ai')->group(function () {
        Route::post('credits/{reservation}/finalize', [InternalAiController::class, 'finalize'])->middleware('ai.service:credits.settle');
        Route::post('credits/{reservation}/release', [InternalAiController::class, 'release'])->middleware('ai.service:credits.settle');
        Route::post('handoff-tickets', [InternalAiController::class, 'handoffTicket'])->middleware('ai.service:tickets.create');
    });

    Route::post('paymongo/webhook', [MemberPaymentController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::post('admin/login/two-factor', [AdminAuthController::class, 'twoFactor'])->middleware('throttle:admin-login');
    Route::get('plans', [AdminSubscriptionController::class, 'catalog']);

    Route::middleware('admin')->group(function () {
        Route::get('admin/me', [AdminAuthController::class, 'me']);
        Route::post('admin/logout', [AdminAuthController::class, 'logout']);
        Route::put('admin/me/password', [AdminAuthController::class, 'password'])->middleware('throttle:password');
        Route::post('admin/me/two-factor', [AdminAuthController::class, 'enableTwoFactor']);
        Route::post('admin/me/two-factor/confirm', [AdminAuthController::class, 'confirmTwoFactor'])->middleware('throttle:password');
        Route::delete('admin/me/two-factor', [AdminAuthController::class, 'disableTwoFactor'])->middleware('throttle:password');
        Route::get('admin/overview', [AdminOverviewController::class, 'show']);
        Route::get('admin/audit', [AdminOverviewController::class, 'audit']);
        Route::get('admin/subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::post('admin/subscriptions', [AdminSubscriptionController::class, 'store']);
        Route::put('admin/subscriptions/{subscription}', [AdminSubscriptionController::class, 'update']);
        // LEGACY: text-only library kept for older admin builds; the knowledge base lives in gabaymo-ai (admin/ai/knowledge/*).
        Route::get('admin/knowledge-documents', [AdminKnowledgeController::class, 'index']);
        Route::post('admin/knowledge-documents', [AdminKnowledgeController::class, 'store']);
        Route::put('admin/knowledge-documents/{knowledgeDocument}', [AdminKnowledgeController::class, 'update']);
        Route::put('admin/knowledge-documents/{knowledgeDocument}/review', [AdminKnowledgeController::class, 'review']);
        Route::get('admin/members', [AdminMemberController::class, 'index']);
        Route::get('admin/members/{member}', [AdminMemberController::class, 'show']);
        Route::post('admin/members/{member}/tokens', [AdminMemberController::class, 'addTokens']);
        Route::get('admin/members/{member}/usage', [AdminMemberController::class, 'usage']);
        Route::post('admin/members/{member}/suspend', [AdminMemberController::class, 'suspend']);
        Route::post('admin/members/{member}/unsuspend', [AdminMemberController::class, 'unsuspend']);
        Route::put('admin/members/{member}/plan', [AdminMemberController::class, 'plan']);
        Route::get('admin/partners', [AdminPartnerController::class, 'index']);
        Route::post('admin/partners', [AdminPartnerController::class, 'store']);
        Route::post('admin/partners/{partner}/suspend', [AdminPartnerController::class, 'suspend']);
        Route::post('admin/partners/{partner}/unsuspend', [AdminPartnerController::class, 'unsuspend']);
        Route::post('admin/partners/{partner}/reset-password', [AdminPartnerController::class, 'resetPassword']);
        Route::post('admin/partners/{partner}/resend-invite', [AdminPartnerController::class, 'resendInvite']);
        Route::get('admin/payments', [AdminPaymentController::class, 'index']);
        Route::get('admin/payments/summary', [AdminPaymentController::class, 'summary']);
        Route::get('admin/tickets', [AdminTicketController::class, 'index']);
        Route::get('admin/tickets/{ticket}', [AdminTicketController::class, 'show']);
        Route::post('admin/tickets/{ticket}/close', [AdminTicketController::class, 'close']);
        Route::get('admin/access-keys', [AdminAccessKeyController::class, 'index']);
        Route::post('admin/access-keys', [AdminAccessKeyController::class, 'store']);
        Route::delete('admin/access-keys/{accessKey}', [AdminAccessKeyController::class, 'destroy']);
        Route::get('admin/referral-codes', [AdminReferralCodeController::class, 'index']);
        Route::post('admin/referral-codes', [AdminReferralCodeController::class, 'store']);
        Route::delete('admin/referral-codes/{referralCode}', [AdminReferralCodeController::class, 'destroy']);
        Route::get('admin/organizations', [AdminOrganizationController::class, 'index']);
        Route::post('admin/organizations', [AdminOrganizationController::class, 'store']);
        Route::put('admin/organizations/{organization}', [AdminOrganizationController::class, 'update']);
        Route::delete('admin/organizations/{organization}', [AdminOrganizationController::class, 'destroy']);
        Route::post('admin/organizations/{organization}/members', [AdminOrganizationController::class, 'addMember']);
        Route::delete('admin/organizations/{organization}/members/{user}', [AdminOrganizationController::class, 'removeMember']);
        Route::get('admin/roles', [AdminRoleController::class, 'index']);
        Route::post('admin/roles', [AdminRoleController::class, 'store']);
        Route::put('admin/roles/{staffRole}', [AdminRoleController::class, 'update']);
        Route::delete('admin/roles/{staffRole}', [AdminRoleController::class, 'destroy']);
        Route::post('admin/roles/{staffRole}/accounts', [AdminRoleController::class, 'storeAccount']);
        Route::post('admin/roles/{staffRole}/accounts/{admin}/reset-password', [AdminRoleController::class, 'resetPassword']);
        Route::post('admin/roles/{staffRole}/accounts/{admin}/resend-invite', [AdminRoleController::class, 'resendInvite']);
        Route::post('admin/roles/{staffRole}/accounts/{admin}/suspend', [AdminRoleController::class, 'suspend']);
        Route::post('admin/roles/{staffRole}/accounts/{admin}/unsuspend', [AdminRoleController::class, 'unsuspend']);
        // LEGACY: superseded by gabaymo-ai instructions (admin/ai/instructions*); kept for older admin builds.
        Route::get('admin/service-instructions', [AdminInstructionController::class, 'index']);
        Route::post('admin/service-instructions', [AdminInstructionController::class, 'store']);
        Route::put('admin/service-instructions/{serviceInstruction}', [AdminInstructionController::class, 'update']);

        // GabayMo AI gateway (module permissions checked per route in AdminAiController).
        Route::get('admin/ai/knowledge/documents/{id}/download', [AdminAiController::class, 'downloadDocument'])->middleware('throttle:ai');
        Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'admin/ai/{path}', [AdminAiController::class, 'forward'])->where('path', '.*')->middleware('throttle:ai');
    });
});
