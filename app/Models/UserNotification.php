<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification for a member or partner (bell + realtime popup).
 */
class UserNotification extends Model
{
    use StoresAppTimezone;

    public const TICKET_CREATED = 'ticket.created';

    public const TICKET_ACCEPTED = 'ticket.accepted';

    public const TICKET_MESSAGE = 'ticket.message';

    public const TICKET_CLOSED = 'ticket.closed';

    public const TICKET_OVERDUE = 'ticket.overdue';

    public const SUBSCRIPTION_EXPIRED = 'subscription.expired';

    public const SUBSCRIPTION_RENEWAL = 'subscription.renewal';

    public const PAYMENT_RECEIPT = 'payment.receipt';

    public const REMINDER_DUE = 'reminder.due';

    public const ISSUANCE_UPDATE = 'issuance.update';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?? (object) [],
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
