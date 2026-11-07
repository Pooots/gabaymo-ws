<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Support\MemberProfile;
use App\Support\SpaSession;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff sign-in: password, then a TOTP code (or a one-time recovery code)
 * when two-factor is on. Repeated failures lock the account for a while.
 */
class AdminAuthController extends Controller
{
    private const PENDING = 'admin_two_factor_pending';

    private const DUMMY_HASH = '$2y$12$1HDDjy7Uf0K968YqjcZxmuB7EZCrGXsbAy62lfs6ekmpiXwvTYWsC';

    public function login(Request $request)
    {
        SpaSession::requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = AdminUser::query()
            ->with('role')
            ->where('email', $credentials['email'])
            ->first();

        if ($user && $this->locked($user)) {
            return $this->lockedResponse();
        }

        $valid = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);
        // Invited staff have no password yet: same answer as a wrong password.
        if (! $user || ! $user->role || ! $valid || $user->isInvited()) {
            if ($user) {
                $this->failed($user, 'password');
            } else {
                AuditLog::record('admin.login_failed', 'Failed staff sign-in for '.MemberProfile::maskEmail($credentials['email']).' from '.$request->ip());
            }

            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        if ($user->isSuspended()) {
            AuditLog::record('admin.login_suspended', 'Suspended staff account '.$user->email.' tried to sign in from '.$request->ip(), null, $user);

            return $this->suspendedResponse();
        }

        if ($user->hasTwoFactor()) {
            $request->session()->put(self::PENDING, ['id' => $user->id, 'expires' => time() + 300]);

            return response()->json(['two_factor_required' => true]);
        }

        return $this->complete($request, $user);
    }

    /** Second step: a 6-digit code from the authenticator app, or a recovery code. */
    public function twoFactor(Request $request)
    {
        SpaSession::requireSession($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        $pending = $request->session()->get(self::PENDING);
        $user = is_array($pending) && ($pending['expires'] ?? 0) >= time()
            ? AdminUser::query()->with('role')->find($pending['id'] ?? 0)
            : null;
        if (! $user || ! $user->role || ! $user->hasTwoFactor()) {
            $request->session()->forget(self::PENDING);

            return response()->json(['message' => 'Sign in with your password first.'], 422);
        }
        if ($this->locked($user)) {
            $request->session()->forget(self::PENDING);

            return $this->lockedResponse();
        }

        if (! $this->acceptCode($user, $data['code'])) {
            $this->failed($user, 'two-factor code');

            return response()->json(['message' => 'That code is not valid.', 'errors' => ['code' => ['That code is not valid.']]], 422);
        }

        $request->session()->forget(self::PENDING);

        return $this->complete($request, $user);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->profile($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        AdminOverviewController::record($request, 'admin.logout', $request->user()->name.' signed out');
        SpaSession::logout($request, 'admin');

        return response()->json(['ok' => true]);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:120', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.', 'errors' => ['current_password' => ['Your current password is incorrect.']]], 422);
        }
        $user->forceFill(['password' => $data['password']])->save();
        SpaSession::revokeAll($request, 'admin', $user, keepCurrent: true);
        AdminOverviewController::record($request, 'admin.password', $user->name.' changed their password');

        return response()->json(['user' => $this->profile($user->fresh('role'))]);
    }

    /** Start (or restart) two-factor setup. Takes effect after confirm. */
    public function enableTwoFactor(Request $request)
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            return response()->json(['message' => 'Two-factor sign-in is already on. Turn it off first to set up a new device.'], 422);
        }
        $secret = Totp::generateSecret();
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => Totp::otpauthUrl((string) config('app.name', 'GabayMo').' Admin', $user->email, $secret),
            'recovery_codes' => $codes,
        ]);
    }

    public function confirmTwoFactor(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        if ((string) $user->two_factor_secret === '' || $user->hasTwoFactor()) {
            return response()->json(['message' => 'Start two-factor setup first.'], 422);
        }
        $step = Totp::verify((string) $user->two_factor_secret, $data['code']);
        if ($step === null) {
            return response()->json(['message' => 'That code is not valid.', 'errors' => ['code' => ['That code is not valid.']]], 422);
        }
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        AdminOverviewController::record($request, 'admin.two_factor_enabled', $user->name.' turned on two-factor sign-in');

        return response()->json(['user' => $this->profile($user->fresh('role'))]);
    }

    public function disableTwoFactor(Request $request)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Your password is incorrect.', 'errors' => ['password' => ['Your password is incorrect.']]], 422);
        }
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
        AdminOverviewController::record($request, 'admin.two_factor_disabled', $user->name.' turned off two-factor sign-in');

        return response()->json(['user' => $this->profile($user->fresh('role'))]);
    }

    private function suspendedResponse()
    {
        return response()->json([
            'message' => 'This account is suspended. Contact a GabayMo administrator.',
            'error' => 'account_suspended',
        ], 403);
    }

    private function complete(Request $request, AdminUser $user)
    {
        if ($user->isSuspended()) {
            return $this->suspendedResponse();
        }
        SpaSession::login($request, 'admin', $user);
        $user->forceFill(['last_login_at' => now(), 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        AdminOverviewController::record($request, 'admin.login', $user->name.' signed in');

        return response()->json([
            'user' => $this->profile($user),
        ]);
    }

    /** TOTP (no replay of an already used time step) or a one-time recovery code. */
    private function acceptCode(AdminUser $user, string $code): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '')) {
            $step = Totp::verify((string) $user->two_factor_secret, $code);
            if ($step === null || ($user->two_factor_last_step !== null && $step <= $user->two_factor_last_step)) {
                return false;
            }
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        $codes = (array) ($user->two_factor_recovery_codes ?? []);
        foreach ($codes as $i => $hash) {
            if (is_string($hash) && Hash::check(Str::lower($code), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                AuditLog::record('admin.recovery_code_used', $user->name.' signed in with a recovery code ('.count($codes).' left)', null, $user);

                return true;
            }
        }

        return false;
    }

    private function locked(AdminUser $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    private function failed(AdminUser $user, string $what): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;
        $max = max(1, (int) config('services.admin_security.max_attempts', 5));
        $lock = $attempts >= $max;
        $user->forceFill([
            'failed_login_attempts' => $lock ? 0 : $attempts,
            'locked_until' => $lock ? now()->addMinutes((int) config('services.admin_security.lock_minutes', 15)) : $user->locked_until,
        ])->save();
        AuditLog::record($lock ? 'admin.locked' : 'admin.login_failed', 'Wrong '.$what.' for '.$user->email.($lock ? ' — account locked' : ''), null, $user);
    }

    private function lockedResponse()
    {
        return response()->json([
            'message' => 'Too many failed sign-in attempts. Try again later.',
            'error' => 'account_locked',
        ], 429);
    }

    /** The `user` payload of admin login / me (also used by invitation accept). */
    public static function profile(AdminUser $user): array
    {
        $role = $user->role;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role->slug,
            'role_name' => $role->name,
            'permissions' => $role->granted(),
            // Administrator role only: may approve their own uploads (recorded as an exception).
            'can_self_approve' => (bool) $role->is_system,
            'two_factor_enabled' => $user->hasTwoFactor(),
        ];
    }
}
