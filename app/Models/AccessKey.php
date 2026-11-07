<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Staff-issued code: a plan for N days and/or extra usage. */
class AccessKey extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['code', 'subscription_plan_id', 'extra_usage_percent', 'duration_days', 'max_uses', 'expires_at', 'note'];

    protected function casts(): array
    {
        return [
            'extra_usage_percent' => 'integer',
            'duration_days' => 'integer',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(AccessKeyRedemption::class);
    }

    public function usable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->uses_count < $this->max_uses;
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'plan_id' => $this->subscription_plan_id,
            'plan' => $this->plan?->name,
            'extra_usage_percent' => $this->extra_usage_percent,
            'duration_days' => $this->duration_days,
            'max_uses' => $this->max_uses,
            'uses_count' => $this->uses_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'note' => $this->note,
            'status' => $this->revoked_at ? 'revoked' : ($this->usable() ? 'active' : 'used_up_or_expired'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
