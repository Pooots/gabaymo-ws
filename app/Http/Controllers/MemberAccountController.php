<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use App\Models\Reminder;
use App\Models\SubscriptionPayment;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\TokenUsageEvent;
use App\Models\UserNotification;
use App\Services\Billing\CodeRedemption;
use App\Services\Billing\SubscriptionService;
use App\Services\GabayMoAi\GabayMoAiUnavailable;
use App\Services\GabayMoAi\MemberAiData;
use App\Support\MemberProfile;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Member self-service: password, sessions, data export and deletion, membership and codes. */
class MemberAccountController extends Controller
{
    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Your current password is incorrect.',
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $user->forceFill(['password' => $data['password']])->save();
        // Other devices are signed out; this one stays signed in.
        SpaSession::revokeAll($request, 'web', $user, keepCurrent: true);
        AdminOverviewController::record($request, $user->isPartner() ? 'partner.password' : 'member.password', $user->name.' changed their password');

        return response()->json(['ok' => true]);
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();
        SpaSession::revokeAll($request, 'web', $user);
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        AdminOverviewController::record($request, 'member.logout_all', $user->name.' signed out of every device');
        SpaSession::logout($request, 'web');

        return response()->json(['ok' => true]);
    }

    public function export(Request $request, MemberAiData $ai)
    {
        $user = $request->user()->loadMissing('subscriptionPlan', 'organization');
        try {
            $aiData = $ai->export($user);
            $aiError = null;
        } catch (GabayMoAiUnavailable $e) {
            report($e);
            $aiData = null;
            $aiError = 'The AI service is temporarily unavailable; conversations could not be included.';
        }

        $tickets = SupportTicket::query()->where('user_id', $user->id)->with('messages')->orderBy('id')->get()
            ->map(fn (SupportTicket $t) => [
                'id' => $t->id, 'subject' => $t->subject, 'body' => $t->body, 'status' => $t->status,
                'practice_area' => $t->practice_area, 'created_at' => $t->created_at?->toIso8601String(),
                'closed_at' => $t->closed_at?->toIso8601String(),
                'messages' => $t->messages->map(fn (TicketMessage $m) => [
                    'from' => (int) $m->user_id === (int) $user->id ? 'you' : 'partner',
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->all(),
            ])->all();

        $export = [
            'exported_at' => now()->toIso8601String(),
            'profile' => MemberProfile::present($user) + [
                'school_name' => $user->school_name,
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'privacy_consent_at' => $user->privacy_consent_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'payments' => SubscriptionPayment::query()->with('plan')->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (SubscriptionPayment $p) => $p->present())->all(),
            'tickets' => $tickets,
            'notifications' => UserNotification::query()->where('user_id', $user->id)->orderBy('id')->get()
                ->map(fn (UserNotification $n) => $n->present())->all(),
            'reminders' => Reminder::query()->where('user_id', $user->id)->orderBy('due_at')->get()
                ->map(fn (Reminder $r) => $r->present())->all(),
            'usage_events' => TokenUsageEvent::query()->where('user_id', $user->id)->orderBy('id')->get(['type', 'percent', 'created_at'])
                ->map(fn (TokenUsageEvent $e) => ['type' => $e->type, 'percent' => round($e->percent, 3), 'created_at' => $e->created_at?->toIso8601String()])->all(),
            'ai' => $aiData,
            'ai_error' => $aiError,
        ];
        AdminOverviewController::record($request, 'member.export', $user->name.' downloaded their data');

        return response()->json($export, 200, [
            'Content-Disposition' => 'attachment; filename="gabaymo-data-'.now()->format('Ymd').'.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Delete the account. Personal data is removed or replaced; the anonymised
     * account row stays (soft-deleted) so payments remain on the books.
     */
    public function destroy(Request $request, MemberAiData $ai)
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Your password is incorrect.',
                'errors' => ['password' => ['Your password is incorrect.']],
            ], 422);
        }

        $aiErased = $ai->erase($user);

        DB::transaction(function () use ($user) {
            UserNotification::query()->where('user_id', $user->id)->delete();
            Reminder::query()->where('user_id', $user->id)->delete();
            $ticketIds = SupportTicket::query()->where('user_id', $user->id)->pluck('id');
            TicketMessage::query()->whereIn('support_ticket_id', $ticketIds)->update(['body' => '[deleted]']);
            SupportTicket::query()->whereIn('id', $ticketIds)->update(['subject' => '[deleted]', 'body' => '[deleted]']);
            SupportTicket::query()->whereIn('id', $ticketIds)->whereIn('status', ['open', 'accepted'])
                ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'member']);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            ReferralCode::query()->where('owner_user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $user->forceFill([
                'name' => 'Deleted member',
                'first_name' => null,
                'last_name' => null,
                'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(8)).'@deleted.gabaymo.invalid',
                'mobile' => null,
                'school_name' => null,
                'region' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'email_verified_at' => null,
                'issuance_alerts' => false,
                'organization_id' => null,
                'session_version' => (int) $user->session_version + 1,
            ])->save();
            $user->delete();
        });

        AdminOverviewController::record($request, 'member.delete', 'Member #'.$user->id.' deleted their account'.($aiErased ? '' : ' (AI data cleanup pending)'));
        SpaSession::logout($request, 'web');

        return response()->noContent();
    }

    public function cancel(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user()->loadMissing('subscriptionPlan');
        if (! $subscriptions->canCancel($user)) {
            return response()->json(['message' => 'There is no paid membership to cancel.'], 422);
        }
        $subscriptions->cancel($user);
        AdminOverviewController::record($request, 'subscription.cancel', $user->name.' cancelled '.$user->subscriptionPlan?->name.' at period end');

        return response()->json(['subscription' => $subscriptions->present($user->fresh('subscriptionPlan'))]);
    }

    public function resume(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user()->loadMissing('subscriptionPlan');
        if (! $user->cancel_at_period_end || ! $subscriptions->canCancel($user)) {
            return response()->json(['message' => 'There is no cancelled membership to resume.'], 422);
        }
        $subscriptions->resume($user);
        AdminOverviewController::record($request, 'subscription.resume', $user->name.' resumed '.$user->subscriptionPlan?->name);

        return response()->json(['subscription' => $subscriptions->present($user->fresh('subscriptionPlan'))]);
    }

    public function redeem(Request $request, CodeRedemption $codes)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $result = $codes->redeemAccessKey($request->user(), $data['code']);

        return response()->json([
            'ok' => true,
            'user' => MemberProfile::present($result['user']),
        ]);
    }
}
