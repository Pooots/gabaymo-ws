<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\IssuanceDigestNotification;
use App\Services\GabayMoAi\AiContext;
use App\Services\GabayMoAi\GabayMoAiClient;
use App\Services\GabayMoAi\GabayMoAiUnavailable;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Daily: ask gabaymo-ai for issuances published (or superseding) since the
 * last run, per member region, and tell members who keep issuance_alerts on
 * (in-app notification per issuance + one email digest).
 */
class SendIssuanceAlertsCommand extends Command
{
    protected $signature = 'issuances:alert {--since= : ISO 8601 start (default: last successful run, or 24 hours ago)}';

    protected $description = 'Notify members about new issuances for their region';

    private const CURSOR = 'issuances:alert:last_run';

    public function handle(GabayMoAiClient $ai, NotificationService $notifications): int
    {
        $startedAt = now();
        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))
            : Carbon::parse((string) Cache::get(self::CURSOR, now()->subDay()->toIso8601String()));

        $regions = User::query()->customers()->where('issuance_alerts', true)->whereNull('suspended_at')
            ->whereNotNull('region')->distinct()->pluck('region');

        $failed = false;
        $notified = 0;
        foreach ($regions as $region) {
            try {
                $response = $ai->send('GET', 'v1/ai/knowledge/updates', AiContext::system(['chat.read']), [
                    'region' => $region,
                    'since' => $since->toIso8601String(),
                ]);
            } catch (GabayMoAiUnavailable $e) {
                report($e);
                $failed = true;

                continue;
            }
            if (! $response->successful()) {
                $this->warn("gabaymo-ai answered HTTP {$response->status()} for {$region}.");
                $failed = true;

                continue;
            }
            $updates = array_values(array_filter((array) ($response->json('data') ?? []), 'is_array'));
            if ($updates === []) {
                continue;
            }

            User::query()->customers()->where('issuance_alerts', true)->whereNull('suspended_at')->where('region', $region)
                ->chunkById(200, function ($members) use ($updates, $notifications, &$notified) {
                    foreach ($members as $member) {
                        foreach ($updates as $update) {
                            $title = trim(($update['issuance_number'] ?? '').' '.($update['title'] ?? 'New issuance'));
                            $body = ! empty($update['supersedes']['issuance_number'])
                                ? 'Supersedes '.$update['supersedes']['issuance_number'].(! empty($update['supersedes']['title']) ? ' — '.Str::limit((string) $update['supersedes']['title'], 80) : '')
                                : null;
                            $notifications->send($member, UserNotification::ISSUANCE_UPDATE, Str::limit('New issuance: '.$title, 180), $body, [
                                'document_id' => $update['document_id'] ?? null,
                                'issuance_number' => $update['issuance_number'] ?? null,
                                'published_at' => $update['published_at'] ?? null,
                            ]);
                        }
                        $notifications->mail($member, new IssuanceDigestNotification($updates));
                        $notified++;
                    }
                });
        }

        // Only move the cursor when every region was fetched, so nothing is skipped.
        if (! $failed) {
            Cache::forever(self::CURSOR, $startedAt->toIso8601String());
        }
        $this->info("Notified {$notified} member(s) across {$regions->count()} region(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
