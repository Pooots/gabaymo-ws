<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's own deadline reminder; sent the day before and on the due date. */
class Reminder extends Model
{
    use StoresAppTimezone;

    protected $fillable = ['user_id', 'title', 'due_at', 'notes', 'conversation_id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'notified_day_before_at' => 'datetime',
            'notified_due_at' => 'datetime',
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
            'title' => $this->title,
            'due_at' => $this->due_at?->toIso8601String(),
            'notes' => $this->notes,
            'conversation_id' => $this->conversation_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
