<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use StoresAppTimezone;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /** School accounts on gabaymo.com. */
    public const CUSTOMER = 'customer';

    /** Partner lawyers on partner.gabaymo.com. */
    public const PARTNER = 'partner';

    /**
     * Profile fields only. Account type, partner status, plan, billing,
     * suspension and security columns are set with forceFill() by the code
     * that owns them, never from request input.
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
        'response_hours',
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
            'token_allowance' => 'integer',
            'tokens_used' => 'integer',
            'tokens_reserved' => 'integer',
            'bonus_tokens' => 'integer',
            'usage_period_started_at' => 'datetime',
            'usage_period_ends_at' => 'datetime',
            'response_hours' => 'integer',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'renewal_reminded_7d_at' => 'datetime',
            'renewal_reminded_1d_at' => 'datetime',
            'suspended_at' => 'datetime',
            'session_version' => 'integer',
            'terms_accepted_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'issuance_alerts' => 'boolean',
            'practice_areas' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Created by staff and waiting for the person to set a password; cannot sign in. */
    public function isInvited(): bool
    {
        return $this->account_status === AccountInvitation::STATUS_INVITED;
    }

    /** invited | active | suspended, for admin lists. */
    public function lifecycleStatus(): string
    {
        return $this->isInvited() ? AccountInvitation::STATUS_INVITED : ($this->isSuspended() ? 'suspended' : 'active');
    }

    /** The newest invitation link that can still be used. */
    public function pendingInvitation(): HasOne
    {
        return $this->hasOne(AccountInvitation::class, 'account_id')
            ->where('account_type', AccountInvitation::PARTNER)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** Link to the SPA reset page (FRONTEND_URL/reset-password?token=&email=). */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
