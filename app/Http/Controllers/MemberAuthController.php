<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Billing\CodeRedemption;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;
use App\Services\Billing\UsageHistory;
use App\Support\MemberProfile;
use App\Support\PhilippineRegions;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MemberAuthController extends Controller
{
    /** Compared against when the email is unknown, so both paths take as long. */
    private const DUMMY_HASH = '$2y$12$1HDDjy7Uf0K968YqjcZxmuB7EZCrGXsbAy62lfs6ekmpiXwvTYWsC';

    public function register(Request $request, CodeRedemption $codes, SubscriptionService $subscriptions)
    {
        SpaSession::requireSession($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'mobile' => ['required', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'region' => ['required', 'string', Rule::in(PhilippineRegions::NAMES)],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
            'accept_terms' => ['accepted'],
            'referral_code' => ['nullable', 'string', 'max:40'],
        ], [
            'mobile.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
            'accept_terms.accepted' => 'Please accept the Terms of Service and Privacy Policy.',
        ]);

        $referral = null;
        if (! empty($data['referral_code'])) {
            $referral = $codes->findUsableReferral($data['referral_code']);
            if (! $referral) {
                return response()->json([
                    'message' => 'This referral code is not valid.',
                    'errors' => ['referral_code' => ['This referral code is not valid.']],
                ], 422);
            }
        }

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);
        $user = new User([
            'name' => trim($first.' '.$last),
            'first_name' => $first,
            'last_name' => $last,
            'mobile' => MemberProfile::mobile($data['mobile']),
            'region' => $data['region'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
        ]);
        $user->forceFill([
            'account_type' => User::CUSTOMER,
            'terms_accepted_at' => now(),
            'privacy_consent_at' => now(),
        ])->save();
        app(TokenMeter::class)->startPlan($user, $subscriptions->freePlan());

        if ($referral) {
            $codes->applyReferral($user, $referral);
        }
        $subscriptions->claimInvites($user);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }

        SpaSession::login($request, 'web', $user);
        AdminOverviewController::record(
            $request,
            'member.register',
            $user->name.' registered from '.$user->region,
        );

        return response()->json([
            'portal' => SpaSession::portal($user),
            'user' => MemberProfile::present($user->fresh()),
        ], 201);
    }

    public function login(Request $request)
    {
        SpaSession::requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Staff accounts live in admin_users, so they are never found here.
        $user = User::query()->where('email', $credentials['email'])->first();
        // Check the password before anything about the account, and answer every
        // failure the same way, so the response never reveals which emails exist.
        $valid = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);
        $portal = $user && ! $user->isInvited() && ($user->isCustomer() || ($user->isPartner() && $user->partner_status === 'active'));

        if (! $user || ! $valid || ! $portal) {
            AuditLog::record(
                'auth.login_failed',
                'Failed sign-in for '.MemberProfile::maskEmail($credentials['email']).' from '.$request->ip(),
                $user && $valid ? $user : null,
            );

            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        if ($user->isSuspended()) {
            AuditLog::record('auth.login_suspended', $user->name.' tried to sign in while suspended', $user);

            return response()->json([
                'message' => 'This account is suspended. Contact GabayMo support.',
                'error' => 'account_suspended',
            ], 403);
        }

        SpaSession::login($request, 'web', $user);
        AdminOverviewController::record($request, $user->isPartner() ? 'partner.login' : 'member.login', $user->name.' signed in');
        if ($user->isCustomer()) {
            $user = app(SubscriptionService::class)->refresh($user);
        }

        return response()->json([
            'portal' => SpaSession::portal($user),
            'user' => MemberProfile::present($user),
        ]);
    }

    /**
     * Who is signed in on the customer/partner session, for the frontend
     * route guards and the shared /login redirect. 401 when nobody is.
     */
    public function session(Request $request)
    {
        $user = Auth::guard('web')->user();
        $active = $user instanceof User
            && ($user->isCustomer() || ($user->isPartner() && $user->partner_status === 'active'))
            && ! $user->isInvited()
            && ! $user->isSuspended()
            && SpaSession::isCurrent($request, 'web', $user);

        if (! $active) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Authentication required',
            ], 401);
        }

        return response()->json([
            'portal' => SpaSession::portal($user),
            'user' => MemberProfile::present($user),
        ]);
    }

    public function me(Request $request)
    {
        $user = app(SubscriptionService::class)->refresh($request->user());

        return response()->json([
            'user' => MemberProfile::present($user),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'region' => ['sometimes', 'required', 'string', Rule::in(PhilippineRegions::NAMES)],
            'issuance_alerts' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $changes = array_intersect_key($data, array_flip(['region', 'issuance_alerts']));
        if ($changes !== []) {
            $user->forceFill($changes)->save();
            AdminOverviewController::record($request, 'member.profile', $user->name.' updated '.implode(', ', array_keys($changes)));
        }

        return response()->json([
            'user' => MemberProfile::present($user->fresh()),
        ]);
    }

    public function logout(Request $request)
    {
        AdminOverviewController::record($request, 'member.logout', $request->user()->name.' signed out');
        SpaSession::logout($request, 'web');

        return response()->json(['ok' => true]);
    }

    /** The member's usage meter and recent usage, in percent. */
    public function usage(Request $request, TokenMeter $meter)
    {
        $user = $request->user()->loadMissing('subscriptionPlan');

        return response()->json([
            'usage' => $meter->snapshot($user),
            'events' => UsageHistory::for($user),
        ]);
    }
}
