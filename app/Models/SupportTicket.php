<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use StoresAppTimezone;

    protected $fillable = [
        'user_id',
        'partner_id',
        'subject',
        'body',
        'status',
        'accepted_at',
        'ai_question_id',
        'practice_area',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'reply_due_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** Waiting on the assigned partner past the reply deadline. */
    public function isOverdue(): bool
    {
        return $this->status === 'accepted' && $this->reply_due_at !== null && $this->reply_due_at->isPast();
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }
}
