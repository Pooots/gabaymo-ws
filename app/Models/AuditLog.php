<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use StoresAppTimezone;

    protected $fillable = [
        'user_id',
        'admin_user_id',
        'action',
        'summary',
    ];

    /** Record an event outside a request (scheduled jobs, webhooks) or for a known actor. */
    public static function record(string $action, string $summary, ?User $user = null, ?AdminUser $admin = null): self
    {
        return static::query()->create([
            'user_id' => $user?->id,
            'admin_user_id' => $admin?->id,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 250),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
