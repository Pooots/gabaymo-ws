<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Services\Billing\CodeRedemption;
use App\Support\Paginates;
use Illuminate\Http\Request;

class AdminAccessKeyController extends Controller
{
    use Paginates;

    public function index(Request $request)
    {
        $query = AccessKey::query()->with('plan')->latest('id');
        $this->search($request, $query, ['code', 'note']);

        return response()->json($this->paginate($request, $query, fn (AccessKey $key) => $key->present()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id', 'required_without:extra_usage_percent'],
            'extra_usage_percent' => ['nullable', 'integer', 'min:1', 'max:500', 'required_without:plan_id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:1095'],
            'max_uses' => ['required', 'integer', 'min:1', 'max:100000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $key = new AccessKey([
            'code' => CodeRedemption::uniqueCode(AccessKey::class),
            'subscription_plan_id' => $data['plan_id'] ?? null,
            'extra_usage_percent' => $data['extra_usage_percent'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'max_uses' => $data['max_uses'],
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        $key->forceFill(['created_by_admin_id' => $request->user()->id])->save();
        AdminOverviewController::record($request, 'access_key.create', 'Created access key '.$key->code.' ('.$key->max_uses.' uses)');

        return response()->json(['access_key' => $key->fresh('plan')->present()], 201);
    }

    public function destroy(Request $request, AccessKey $accessKey)
    {
        if (! $accessKey->revoked_at) {
            $accessKey->forceFill(['revoked_at' => now()])->save();
            AdminOverviewController::record($request, 'access_key.revoke', 'Revoked access key '.$accessKey->code);
        }

        return response()->json(['access_key' => $accessKey->fresh('plan')->present()]);
    }
}
