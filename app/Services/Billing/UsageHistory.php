<?php

namespace App\Services\Billing;

use App\Models\TokenUsageEvent;
use App\Models\User;

/** A member's recent usage ledger, in percent (staff also see token counts). */
class UsageHistory
{
    /** @return list<array<string, mixed>> */
    public static function for(User $user, bool $withRaw = false, int $limit = 50): array
    {
        return TokenUsageEvent::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (TokenUsageEvent $event) => array_filter([
                'id' => $event->id,
                'type' => $event->type,
                'percent' => round($event->percent, 2),
                'question_id' => $event->ai_question_id,
                'created_at' => $event->created_at?->toIso8601String(),
                'tokens' => $withRaw ? $event->tokens : null,
                'input_tokens' => $withRaw ? $event->input_tokens : null,
                'cached_input_tokens' => $withRaw ? $event->cached_input_tokens : null,
                'output_tokens' => $withRaw ? $event->output_tokens : null,
                'note' => $event->meta['note'] ?? null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }
}
