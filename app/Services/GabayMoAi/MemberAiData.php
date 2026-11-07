<?php

namespace App\Services\GabayMoAi;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/** A member's data held by gabaymo-ai: exported with the account, erased with it. */
class MemberAiData
{
    private const MAX_PAGES = 50;

    public function __construct(private readonly GabayMoAiClient $ai) {}

    /** @return list<array<string, mixed>> conversations with their messages */
    public function export(User $user): array
    {
        $context = AiContext::member($user);
        $out = [];
        foreach ($this->conversations($user) as $conversation) {
            $id = (string) ($conversation['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $messages = $this->ai->send('GET', "v1/ai/conversations/{$id}/messages", $context, ['per_page' => 200]);
            $out[] = $conversation + ['messages' => $messages->successful() ? ($messages->json('data') ?? []) : null];
        }

        $memory = $this->ai->send('GET', 'v1/ai/memory', $context);

        return ['conversations' => $out, 'memory' => $memory->successful() ? $memory->json('data') : null];
    }

    /** Delete the member's memory and every conversation. False if anything could not be deleted. */
    public function erase(User $user): bool
    {
        $context = AiContext::member($user);
        $ok = true;
        try {
            $ok = $this->ai->send('DELETE', 'v1/ai/memory', $context, [], null)->successful() && $ok;
            foreach ($this->conversations($user) as $conversation) {
                $id = (string) ($conversation['id'] ?? '');
                if ($id !== '') {
                    $response = $this->ai->send('DELETE', "v1/ai/conversations/{$id}", $context, [], null);
                    $ok = ($response->successful() || $response->status() === 404) && $ok;
                }
            }
        } catch (GabayMoAiUnavailable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            Log::warning('gabaymo-ai data could not be fully erased for a deleted member.', ['user_id' => $user->id]);
        }

        return $ok;
    }

    /** @return list<array<string, mixed>> */
    private function conversations(User $user): array
    {
        $context = AiContext::member($user);
        $all = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->ai->send('GET', 'v1/ai/conversations', $context, ['page' => $page, 'per_page' => 100]);
            if (! $response->successful()) {
                throw new GabayMoAiUnavailable('Conversation list failed with HTTP '.$response->status().'.');
            }
            $rows = (array) ($response->json('data') ?? []);
            $all = [...$all, ...array_values(array_filter($rows, 'is_array'))];
            $last = (int) ($response->json('meta.last_page') ?? $page);
            if ($rows === [] || $page >= $last) {
                break;
            }
        }

        return $all;
    }
}
