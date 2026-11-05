<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** School accounts on gabaymo.com. */
    public const CUSTOMER = 'customer';

    /** Partner lawyers on partner.gabaymo.com. */
    public const PARTNER = 'partner';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'school_name',
        'mobile',
        'region',
        'specialty',
        'account_type',
        'response_hours',
        'partner_status',
        'subscription_plan_id',
        'tokens_remaining',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tokens_remaining' => 'integer',
            'response_hours' => 'integer',
        ];
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function isCustomer(): bool
    {
        return $this->account_type === self::CUSTOMER;
    }

    public function isPartner(): bool
    {
        return $this->account_type === self::PARTNER;
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('account_type', self::CUSTOMER);
    }

    public function scopePartners(Builder $query): Builder
    {
        return $query->where('account_type', self::PARTNER);
    }
}
