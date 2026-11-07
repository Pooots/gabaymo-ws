<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Personal notification channel. Only the exact member or partner may listen.
 * Authorized through POST /api/v1/broadcasting/auth (session cookie).
 */
Broadcast::channel('notifications.user.{userId}', function ($user, string $userId) {
    return $user instanceof User && (int) $user->id === (int) $userId;
});
