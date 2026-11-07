<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Group plan (school, division): its members get its plan until period_end. */
class Organization extends Model
{
    use StoresAppTimezone;

    public const TYPES = ['school', 'division', 'other'];

    protected $fillable = ['name', 'type', 'region', 'seats', 'subscription_plan_id', 'period_end'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'period_end' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(OrganizationInvite::class);
    }

    public function isActive(): bool
    {
        return $this->period_end !== null && $this->period_end->isFuture();
    }

    /** Members plus pending invites; both hold a seat. */
    public function seatsUsed(): int
    {
        return $this->members()->count() + $this->invites()->count();
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'region' => $this->region,
            'seats' => $this->seats,
            'seats_used' => $this->seatsUsed(),
            'subscription_plan_id' => $this->subscription_plan_id,
            'plan' => $this->plan?->name,
            'period_end' => $this->period_end?->toIso8601String(),
            'active' => $this->isActive(),
            'members' => $this->members()->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
            'invites' => $this->invites()->orderBy('email')->pluck('email')->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
