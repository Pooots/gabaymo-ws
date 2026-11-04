<?php

namespace App\Http\Controllers;

use App\Models\User;

class AdminMemberController extends Controller
{
    public function index()
    {
        $members = User::query()
            ->where('role', 'member')
            ->with('subscriptionPlan')
            ->latest()
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'region' => $user->region,
                'plan' => $user->subscriptionPlan?->name ?? 'Free Trial',
                'tokens_remaining' => $user->tokens_remaining,
                'joined_at' => $user->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            ]);

        return response()->json(['members' => $members]);
    }
}
