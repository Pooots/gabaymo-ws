<?php

namespace App\Models;

use App\Models\Concerns\StoresAppTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditReservation extends Model
{
    use StoresAppTimezone;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'amount', 'charged_tokens', 'status', 'idempotency_key', 'ai_question_id', 'usage', 'reason', 'settled_at'];

    protected function casts(): array
    {
        return ['usage' => 'array', 'settled_at' => 'datetime', 'amount' => 'integer', 'charged_tokens' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
