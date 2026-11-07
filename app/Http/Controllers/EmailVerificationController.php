<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request)
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If your email is not yet confirmed, a new link is on its way.'], 202);
    }

    /** Signed link from the verification email; lands on the SPA account page. */
    public function verify(Request $request, int $id, string $hash)
    {
        $frontend = rtrim((string) config('services.frontend_url'), '/');
        $user = User::query()->find($id);

        if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1((string) $user->email), $hash)) {
            return redirect()->away($frontend.'/account?verified=0');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
            AuditLog::record('member.email_verified', $user->name.' confirmed their email address', $user);
        }

        return redirect()->away($frontend.'/account?verified=1');
    }
}
