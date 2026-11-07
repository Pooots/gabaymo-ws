<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Billing\CodeRedemption;
use App\Support\Paginates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReferralCodeController extends Controller
{
    use Paginates;

    public function index(Request $request)
    {
        $query = ReferralCode::query()->with('owner')->latest('id');
        $this->search($request, $query, ['code']);

        return response()->json($this->paginate($request, $query, fn (ReferralCode $code) => $code->present()));
    }

    public function store(Request $request)
    {
        $request->merge(['code' => $request->filled('code') ? CodeRedemption::normalize((string) $request->input('code')) : null]);
        $data = $request->validate([
            'code' => ['nullable', 'string', 'min:4', 'max:40', 'regex:/^[A-Z0-9-]+$/', Rule::unique('referral_codes', 'code')],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('account_type', User::CUSTOMER)->whereNull('deleted_at')],
            'reward_extra_usage_percent' => ['required', 'integer', 'min:1', 'max:500'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ], ['code.regex' => 'Use letters, numbers and dashes only.']);

        $code = new ReferralCode([
            'code' => CodeRedemption::uniqueCode(ReferralCode::class, $data['code'] ?? null),
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'reward_extra_usage_percent' => $data['reward_extra_usage_percent'],
            'max_uses' => $data['max_uses'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        $code->forceFill(['created_by_admin_id' => $request->user()->id])->save();
        AdminOverviewController::record($request, 'referral_code.create', 'Created referral code '.$code->code);

        return response()->json(['referral_code' => $code->fresh('owner')->present()], 201);
    }

    public function destroy(Request $request, ReferralCode $referralCode)
    {
        if (! $referralCode->revoked_at) {
            $referralCode->forceFill(['revoked_at' => now()])->save();
            AdminOverviewController::record($request, 'referral_code.revoke', 'Revoked referral code '.$referralCode->code);
        }

        return response()->json(['referral_code' => $referralCode->fresh('owner')->present()]);
    }
}
