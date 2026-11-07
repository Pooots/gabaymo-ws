<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared at sign-up: the new member and the owner both get extra usage. */
class ReferralCode extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['code', 'owner_user_id', 'reward_extra_usage_percent', 'max_uses', 'expires_at'];

    protected function casts(): array
    {
        return [
            'reward_extra_usage_percent' => 'integer',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function usable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses_count < $this->max_uses);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name, 'email' => $this->owner->email] : null,
            'reward_extra_usage_percent' => $this->reward_extra_usage_percent,
            'max_uses' => $this->max_uses,
            'uses_count' => $this->uses_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->revoked_at ? 'revoked' : ($this->usable() ? 'active' : 'used_up_or_expired'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
