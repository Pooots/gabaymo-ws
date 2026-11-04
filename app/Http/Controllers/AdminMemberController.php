<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMemberController extends Controller
{
    public function index()
    {
        $members = User::query()
            ->where('role', 'member')
            ->where('type', 'Users')
            ->with('subscriptionPlan')
            ->latest()
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json(['members' => $members]);
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

    public function addTokens(Request $request, User $member)
    {
        if (! $this->isMember($member)) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $updated = DB::transaction(function () use ($request, $member, $data) {
            $locked = User::query()->whereKey($member->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }
            $next = (int) $locked->tokens_remaining + (int) $data['amount'];
            if ($next > 1000000) {
                return null;
            }

            $locked->forceFill(['tokens_remaining' => $next])->save();
            AdminOverviewController::record(
                $request,
                'member.tokens',
                'Added '.$data['amount'].' tokens to '.$locked->name.'.',
            );

            return $locked;
        });

        if ($updated === false) {
            return response()->json(['message' => 'This member was not found.'], 404);
        }

        if (! $updated) {
            return response()->json([
                'message' => 'That would put this wallet above 1,000,000 tokens.',
            ], 422);
        }

        return response()->json([
            'member' => $this->present($updated->fresh('subscriptionPlan')),
        ]);
    }

    private function isMember(User $member): bool
    {
        return $member->role === 'member' && $member->type === 'Users';
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
            'tokens_remaining' => (int) $user->tokens_remaining,
            'joined_at' => $user->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
