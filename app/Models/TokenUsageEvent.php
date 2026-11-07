<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ledger of a member's token allowance and usage. */
class TokenUsageEvent extends Model
{
    use StoresAppTimezone;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'type', 'tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens',
        'percent', 'reservation_id', 'ai_question_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'percent' => 'float',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
