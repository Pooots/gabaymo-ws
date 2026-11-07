<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Accounts\AccountInvitations;
use App\Support\MemberProfile;
use App\Support\Paginates;
use App\Support\PracticeAreas;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPartnerController extends Controller
{
    use Paginates;

    public function __construct(private AccountInvitations $invitations) {}

    public function index(Request $request)
    {
        $openTickets = SupportTicket::query()->where('status', 'open')->count();
        $query = User::query()->partners()->with('pendingInvitation')->latest('id');
        $this->search($request, $query, ['name', 'email', 'specialty', 'mobile']);

        $result = $this->paginate($request, $query, fn (User $user) => $this->present($user, $openTickets), 'partners');
        $result['practice_areas'] = PracticeAreas::KEYS;

        return response()->json($result);
    }

    /**
     * Creates the partner as "invited" (no password, cannot sign in) and
     * emails a set-up link; the partner chooses the password and finishes
     * the profile on /setup-account.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required_without_all:first_name,last_name', 'nullable', 'string', 'max:120'],
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:80'],
            'last_name' => ['required_without:name', 'nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'specialty' => ['nullable', 'string', 'max:160'],
            'title' => ['nullable', 'string', 'max:120'],
            'response_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'partner_status' => ['sometimes', 'in:active,inactive'],
            'practice_areas' => ['sometimes', 'array'],
            'practice_areas.*' => ['string', Rule::in(PracticeAreas::KEYS)],
        ], [
            'mobile.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
        ]);

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $name = trim((string) ($data['name'] ?? '')) ?: trim($first.' '.$last);
        $user = new User([
            'name' => $name,
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'email' => $data['email'],
            'mobile' => ! empty($data['mobile']) ? MemberProfile::mobile($data['mobile']) : null,
            'specialty' => isset($data['specialty']) ? trim($data['specialty']) : null,
            'response_hours' => $data['response_hours'] ?? 24,
        ]);
        $user->forceFill([
            'account_type' => User::PARTNER,
            'account_status' => AccountInvitation::STATUS_INVITED,
            'title' => isset($data['title']) ? trim($data['title']) : null,
            'partner_status' => $data['partner_status'] ?? 'active',
            'practice_areas' => array_values(array_unique($data['practice_areas'] ?? [])),
        ])->save();

        $this->invitations->issue($user, $request->user());
        AdminOverviewController::record(
            $request,
            'partner.create',
            $user->name.' was invited as a partner',
        );

        return response()->json([
            'partner' => $this->present($user->fresh('pendingInvitation'), SupportTicket::query()->where('status', 'open')->count()),
        ], 201);
    }

    /** New set-up link for a partner who has not accepted yet; the old link stops working. */
    public function resendInvite(Request $request, User $partner)
    {
        if (! $partner->isPartner()) {
            return response()->json(['message' => 'This partner was not found.'], 404);
        }
        if (! $partner->isInvited()) {
            return response()->json(['message' => 'This partner already set up their account. Use reset password instead.'], 422);
        }
        $this->invitations->issue($partner, $request->user());
        AdminOverviewController::record($request, 'partner.resend_invite', 'Sent a new set-up link to partner '.$partner->name);

        return response()->json(['partner' => $this->present($partner->fresh('pendingInvitation'))], 202);
    }

    public function suspend(Request $request, User $partner)
    {
        if (! $partner->isPartner()) {
            return response()->json(['message' => 'This partner was not found.'], 404);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $partner->forceFill(['suspended_at' => now(), 'suspension_reason' => $data['reason'] ?? null])->save();
        SpaSession::revokeAll($request, 'web', $partner);
        AdminOverviewController::record($request, 'partner.suspend', 'Suspended partner '.$partner->name.(! empty($data['reason']) ? ': '.$data['reason'] : ''));

        return response()->json(['partner' => $this->present($partner->fresh())]);
    }

    public function unsuspend(Request $request, User $partner)
    {
        if (! $partner->isPartner()) {
            return response()->json(['message' => 'This partner was not found.'], 404);
        }
        $partner->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        AdminOverviewController::record($request, 'partner.unsuspend', 'Lifted the suspension of partner '.$partner->name);

        return response()->json(['partner' => $this->present($partner->fresh())]);
    }

    /** Emails a link to choose a new password (the current one keeps working until then). */
    public function resetPassword(Request $request, User $partner)
    {
        if (! $partner->isPartner()) {
            return response()->json(['message' => 'This partner was not found.'], 404);
        }
        $issued = $this->invitations->issue($partner, $request->user(), AccountInvitation::RESET);
        AdminOverviewController::record($request, 'partner.reset_password', 'Sent a password link to partner '.$partner->name);

        return response()->json([
            'message' => 'We emailed '.$partner->email.' a link to set a new password.',
            'expires_at' => $issued['invitation']->expires_at->toIso8601String(),
        ], 202);
    }

    private function present(User $user, int $openTickets = 0): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'specialty' => $user->specialty,
            'practice_areas' => array_values((array) ($user->practice_areas ?? [])),
            'open_tickets' => $openTickets,
            'response_hours' => $user->response_hours,
            'partner_status' => $user->partner_status,
            'suspended' => $user->isSuspended(),
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'title' => $user->title,
            'bio' => $user->bio,
            'photo_url' => $user->photo_url,
            // invited | active | suspended
            'status' => $user->lifecycleStatus(),
            'invitation_expires_at' => $user->pendingInvitation?->expires_at?->toIso8601String(),
        ];
    }
}
