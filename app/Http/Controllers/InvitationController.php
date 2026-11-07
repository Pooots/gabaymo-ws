<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounts\AccountInvitations;
use App\Support\MemberProfile;
use App\Support\PracticeAreas;
use App\Support\SpaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Public /setup-account page API: look up an emailed invitation link, then
 * set the password (and, for a new partner, the profile) and sign in.
 */
class InvitationController extends Controller
{
    public function __construct(private AccountInvitations $invitations) {}

    /** GET /v1/invitations/{token}?email= */
    public function show(Request $request, string $token)
    {
        $invitation = $this->invitations->find($token, $request->query('email'));
        if (! $invitation) {
            return $this->invalid();
        }
        /** @var User|AdminUser $account */
        $account = $invitation->getRelation('resolvedAccount');
        $partner = $invitation->account_type === AccountInvitation::PARTNER;

        return response()->json([
            'email' => $account->email,
            'name' => $account->name,
            'account_type' => $invitation->account_type,
            'purpose' => $invitation->purpose,
            'role' => $account instanceof AdminUser && $account->role
                ? ['slug' => $account->role->slug, 'name' => $account->role->name]
                : null,
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'fields_required' => $this->required($invitation),
            'fields_optional' => $this->optional($invitation),
            'practice_area_options' => $partner ? PracticeAreas::KEYS : null,
            'prefill' => $partner ? [
                'phone' => $account->mobile,
                'title' => $account->title,
                'bio' => $account->bio,
                'practice_areas' => array_values((array) ($account->practice_areas ?? [])),
            ] : null,
        ]);
    }

    /** POST /v1/invitations/accept */
    public function accept(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'max:120'],
        ]);
        $invitation = $this->invitations->find((string) $request->input('token'), (string) $request->input('email'));
        if (! $invitation) {
            return $this->invalid();
        }
        $data = $request->validate($this->rules($invitation), [
            'phone.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
        ]);

        $account = DB::transaction(function () use ($invitation, $data) {
            // One use only, even when two requests race.
            $locked = AccountInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isUsable()) {
                return null;
            }
            $account = $locked->account();
            if (! $account) {
                return null;
            }
            $this->apply($account, $locked, $data);
            $locked->forceFill(['accepted_at' => now()])->save();
            // Other links for this account stop working.
            $this->invitations->revokeOpen($locked->account_type, (int) $account->id);

            return $account;
        });
        if (! $account) {
            return $this->invalid();
        }

        $setup = $invitation->purpose === AccountInvitation::SETUP;
        if ($account instanceof AdminUser) {
            AuditLog::record($setup ? 'admin.invitation_accepted' : 'admin.password_link_used', $account->name.($setup ? ' set up their staff account' : ' set a new password from an emailed link'), null, $account);
        } else {
            AuditLog::record($setup ? 'partner.invitation_accepted' : 'partner.password_link_used', $account->name.($setup ? ' set up their partner account' : ' set a new password from an emailed link'), $account);
        }

        return $this->signIn($request, $account);
    }

    /** Sign in like a normal login when this origin has a session and nothing else is needed. */
    private function signIn(Request $request, User|AdminUser $account): JsonResponse
    {
        $type = AccountInvitations::typeOf($account);
        $canSignIn = $request->hasSession() && (
            $account instanceof AdminUser
                ? ! $account->hasTwoFactor() && $account->role !== null && ! $account->isSuspended()
                : $account->partner_status === 'active' && ! $account->isSuspended()
        );
        if (! $canSignIn) {
            return response()->json(['signed_in' => false, 'account_type' => $type]);
        }

        if ($account instanceof AdminUser) {
            SpaSession::login($request, 'admin', $account);
            $account->forceFill(['last_login_at' => now()])->save();

            return response()->json(['signed_in' => true, 'account_type' => $type, 'user' => AdminAuthController::profile($account)]);
        }

        SpaSession::login($request, 'web', $account);

        return response()->json([
            'signed_in' => true,
            'account_type' => $type,
            'portal' => SpaSession::portal($account),
            'user' => MemberProfile::present($account),
        ]);
    }

    private function apply(User|AdminUser $account, AccountInvitation $invitation, array $data): void
    {
        $setup = $invitation->purpose === AccountInvitation::SETUP;
        $attributes = ['password' => $data['password'], 'account_status' => AccountInvitation::STATUS_ACTIVE];

        if ($setup) {
            $attributes['name'] = trim($data['name']);
        }
        if ($account instanceof User) {
            $attributes['email_verified_at'] = $account->email_verified_at ?? now();
            if ($setup) {
                [$first, $last] = array_pad(explode(' ', $attributes['name'], 2), 2, null);
                $attributes += [
                    'first_name' => mb_substr($first, 0, 80),
                    'last_name' => $last !== null ? mb_substr($last, 0, 80) : null,
                    'practice_areas' => array_values(array_unique($data['practice_areas'])),
                ];
                if (array_key_exists('phone', $data)) {
                    $attributes['mobile'] = $data['phone'] !== null ? MemberProfile::mobile($data['phone']) : null;
                }
                foreach (['title', 'bio'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $attributes[$field] = $data[$field] !== null ? trim($data[$field]) : null;
                    }
                }
            }
        } else {
            $attributes += ['failed_login_attempts' => 0, 'locked_until' => null];
        }

        // Sessions from before the new password stop working.
        $attributes['session_version'] = (int) $account->session_version + 1;
        $account->forceFill($attributes)->save();
    }

    private function rules(AccountInvitation $invitation): array
    {
        $admin = $invitation->account_type === AccountInvitation::ADMIN;
        $rules = [
            // Same minimums as the change-password endpoints of each portal.
            'password' => ['required', 'string', 'min:'.($admin ? 10 : 8), 'max:120', 'confirmed'],
        ];
        if ($invitation->purpose !== AccountInvitation::SETUP) {
            return $rules;
        }
        $rules['name'] = ['required', 'string', 'max:120'];
        if (! $admin) {
            $rules += [
                'practice_areas' => ['required', 'array', 'min:1'],
                'practice_areas.*' => ['required', 'string', Rule::in(PracticeAreas::KEYS)],
                'phone' => ['nullable', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
                'title' => ['nullable', 'string', 'max:120'],
                'bio' => ['nullable', 'string', 'max:2000'],
            ];
        }

        return $rules;
    }

    private function required(AccountInvitation $invitation): array
    {
        if ($invitation->purpose !== AccountInvitation::SETUP) {
            return ['password'];
        }

        return $invitation->account_type === AccountInvitation::PARTNER
            ? ['name', 'password', 'practice_areas']
            : ['name', 'password'];
    }

    private function optional(AccountInvitation $invitation): array
    {
        return $invitation->purpose === AccountInvitation::SETUP && $invitation->account_type === AccountInvitation::PARTNER
            ? ['phone', 'title', 'bio']
            : [];
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'message' => 'This link is invalid, has expired or was already used. Ask your administrator for a new one.',
            'error' => 'invitation_invalid',
        ], 410);
    }
}
