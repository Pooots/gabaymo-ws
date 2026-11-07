<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;
use App\Services\Billing\UsageHistory;
use App\Support\Paginates;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminMemberController extends Controller
{
    use Paginates;

    public function __construct(private readonly TokenMeter $meter, private readonly SubscriptionService $subscriptions) {}

    /** Read-only: the usage snapshot is computed from the loaded row, nothing is written. */
    public function index(Request $request)
    {
        $query = User::query()->customers()->with('subscriptionPlan', 'organization')->latest('id');
        $this->search($request, $query, ['name', 'email', 'mobile', 'school_name', 'region']);
        if ($status = $request->query('status')) {
            $status === 'suspended' ? $query->whereNotNull('suspended_at') : $query->where('subscription_status', (string) $status);
        }

        return response()->json($this->paginate($request, $query, fn (User $user) => $this->present($user), 'members'));
    }

    public function suspend(Request $request, User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $member->forceFill(['suspended_at' => now(), 'suspension_reason' => trim($data['reason'])])->save();
        SpaSession::revokeAll($request, 'web', $member);
        AdminOverviewController::record($request, 'member.suspend', 'Suspended '.$member->name.': '.trim($data['reason']));

        return response()->json(['member' => $this->present($member->fresh(['subscriptionPlan', 'organization']))]);
    }

    public function unsuspend(Request $request, User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }
        $member->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        AdminOverviewController::record($request, 'member.unsuspend', 'Lifted the suspension of '.$member->name);

        return response()->json(['member' => $this->present($member->fresh(['subscriptionPlan', 'organization']))]);
    }

    /** Staff plan change. A paid plan runs until period_end (default: one billing interval). */
    public function plan(Request $request, User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }
        $data = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'period_end' => ['nullable', 'date', 'after:now'],
        ]);
        $plan = SubscriptionPlan::query()->findOrFail($data['subscription_plan_id']);
        $end = isset($data['period_end']) ? Carbon::parse($data['period_end']) : null;
        $updated = $this->subscriptions->assign($member, $plan, $end, 'admin');
        AdminOverviewController::record($request, 'member.plan', 'Moved '.$member->name.' to '.$plan->name.($updated->current_period_end ? ' until '.$updated->current_period_end->toDateString() : ''));

        return response()->json(['member' => $this->present($updated->fresh(['subscriptionPlan', 'organization']))]);
    }

    public function show(User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }

        return response()->json([
            'member' => $this->present($member->loadMissing('subscriptionPlan')),
        ]);
    }

    /** Grant extra usage, sized as a percent of the member's plan allowance. Carries over between periods. */
    public function addTokens(Request $request, User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }

        $data = $request->validate([
            'percent' => ['required', 'numeric', 'min:1', 'max:500'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        $member->loadMissing('subscriptionPlan');
        $base = (int) ($member->token_allowance ?: $member->subscriptionPlan?->token_allowance ?: 0);
        if ($base <= 0) {
            return response()->json(['message' => 'This member has no plan allowance to size extra usage from.'], 422);
        }
        $tokens = (int) round($base * (float) $data['percent'] / 100);
        if ((int) $member->bonus_tokens + $tokens > $base * 10) {
            return response()->json(['message' => 'Extra usage cannot go above 1,000% of the plan allowance.'], 422);
        }

        $updated = $this->meter->grantExtra($member, $tokens, $data['note'] ?? null);
        AdminOverviewController::record(
            $request,
            'member.tokens',
            'Granted '.rtrim(rtrim(number_format((float) $data['percent'], 1), '0'), '.').'% extra usage to '.$updated->name.'.',
        );

        return response()->json([
            'member' => $this->present($updated->fresh('subscriptionPlan')),
        ]);
    }

    public function usage(User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }

        return response()->json([
            'usage' => $this->meter->snapshot($member->loadMissing('subscriptionPlan'), true),
            'events' => UsageHistory::for($member, true),
        ]);
    }

    private function isMember(User $member): bool
    {
        return $member->isCustomer();
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'region' => $user->region,
            'school_name' => $user->school_name,
            'plan' => $user->subscriptionPlan?->name ?? 'Free Trial',
            'plan_price' => (int) ($user->subscriptionPlan?->price ?? 0),
            'plan_cadence' => $user->subscriptionPlan?->cadence,
            'subscription' => $this->subscriptions->present($user),
            'email_verified' => $user->hasVerifiedEmail(),
            'suspended' => $user->isSuspended(),
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'suspension_reason' => $user->suspension_reason,
            'usage' => $this->meter->snapshot($user, true),
            'joined_at' => $user->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
