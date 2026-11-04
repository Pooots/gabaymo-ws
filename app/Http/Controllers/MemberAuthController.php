<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\PhilippineRegions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberAuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'mobile' => ['required', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'region' => ['required', 'string', Rule::in(PhilippineRegions::NAMES)],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
        ], [
            'mobile.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
        ]);

        $plan = SubscriptionPlan::query()
            ->where('active', true)
            ->where('price', 0)
            ->orderBy('sort_order')
            ->first();

        $first = trim($data['first_name']);
        $last = trim($data['last_name']);
        $user = User::query()->create([
            'name' => trim($first.' '.$last),
            'first_name' => $first,
            'last_name' => $last,
            'mobile' => $this->mobile($data['mobile']),
            'region' => $data['region'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'member',
            'type' => 'Users',
            'subscription_plan_id' => $plan?->id,
            'tokens_remaining' => $plan?->tokens ?? 0,
            'email_verified_at' => now(),
        ]);

        $plain = $this->issueToken($user);
        $request->setUserResolver(static fn () => $user);
        AdminOverviewController::record(
            $request,
            'member.register',
            $user->name.' registered from '.$user->region,
        );

        return response()->json([
            'token' => $plain,
            'user' => $this->profile($user->load('subscriptionPlan')),
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        $staff = $user ? Role::query()->where('slug', $user->role)->exists() : false;

        if ($user && $staff) {
            return response()->json([
                'message' => 'This account signs in on the admin page.',
            ], 422);
        }

        $partner = $user && ($user->role === 'partner' || $user->type === 'partners');
        $member = $user && $user->role === 'member';

        if ($partner && $user->partner_status !== 'active') {
            return response()->json([
                'message' => 'This partner account is not active.',
            ], 422);
        }

        if ((! $partner && ! $member) || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        $plain = $this->issueToken($user);

        return response()->json([
            'token' => $plain,
            'user' => $this->profile($user->load('subscriptionPlan')),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->profile($request->user()->load('subscriptionPlan')),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'region' => ['required', 'string', Rule::in(PhilippineRegions::NAMES)],
        ]);

        $user = $request->user();
        $user->forceFill(['region' => $data['region']])->save();

        return response()->json([
            'user' => $this->profile($user->load('subscriptionPlan')),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->forceFill(['api_token' => null])->save();

        return response()->json(['ok' => true]);
    }

    private function issueToken(User $user): string
    {
        $plain = Str::random(64);
        $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

        return $plain;
    }

    private function profile(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'region' => $user->region,
            'specialty' => $user->specialty,
            'type' => $user->type,
            'response_hours' => $user->response_hours,
            'plan' => $user->subscriptionPlan?->name ?? 'Free Trial',
            'plan_price' => (int) ($user->subscriptionPlan?->price ?? 0),
            'tokens_remaining' => $user->tokens_remaining,
        ];
    }

    private function mobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }
}
