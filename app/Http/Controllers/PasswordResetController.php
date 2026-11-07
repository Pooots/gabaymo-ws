<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/** Members and partners (users table). Staff passwords are reset by an administrator. */
class PasswordResetController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:120']]);

        $user = User::query()->where('email', $data['email'])->first();
        // Invited partners finish set-up through their invitation link instead.
        if ($user && ! $user->isSuspended() && ! $user->isInvited()) {
            // Throttled and unknown emails get the same answer: no enumeration.
            $status = Password::broker('users')->sendResetLink(['email' => $data['email']]);
            if ($status === Password::RESET_LINK_SENT) {
                AuditLog::record('auth.password_reset_requested', $user->name.' asked for a password reset link', $user);
            }
        }

        return response()->json([
            'message' => 'If an account exists for that email, we sent a link to reset the password.',
        ], 202);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
        ]);

        $status = Password::broker('users')->reset($data, function (User $user, string $password) use ($request) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            SpaSession::revokeAll($request, 'web', $user);
            AuditLog::record('auth.password_reset', $user->name.' reset their password', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
                'errors' => ['token' => ['This password reset link is invalid or has expired.']],
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Your password was reset. You can sign in now.']);
    }
}
