<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A set-up (or admin-sent password) link for a partner or staff account.
 * Only the SHA-256 of the token is stored; see App\Services\AccountInvitations.
 */
class AccountInvitation extends Model
{
    use StoresAppTimezone;

    public const PARTNER = 'partner';

    public const ADMIN = 'admin';

    public const SETUP = 'setup';

    public const RESET = 'reset';

    /** account_status of a users / admin_users row that has not set a password yet. */
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'invited_by');
    }

    /** The users (partner) or admin_users row this link is for. */
    public function account(): User|AdminUser|null
    {
        return $this->account_type === self::ADMIN
            ? AdminUser::query()->with('role')->find($this->account_id)
            : User::query()->find($this->account_id);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
