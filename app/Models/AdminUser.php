<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Staff account for the admin portal ("admin" guard). Customers and partners
 * are App\Models\User and can never authenticate on this guard.
 */
class AdminUser extends Authenticatable
{
    use StoresAppTimezone;

    protected $fillable = [
        'name',
        'email',
        'role_id',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'session_version' => 'integer',
        ];
    }

    /** Created by staff and waiting for the person to set a password; cannot sign in. */
    public function isInvited(): bool
    {
        return $this->account_status === AccountInvitation::STATUS_INVITED;
    }

    /** Suspended by another staff member: cannot sign in, existing sessions are rejected. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** The newest invitation link that can still be used. */
    public function pendingInvitation(): HasOne
    {
        return $this->hasOne(AccountInvitation::class, 'account_id')
            ->where('account_type', AccountInvitation::ADMIN)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id');
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && (string) $this->two_factor_secret !== '';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
