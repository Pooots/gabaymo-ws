<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\UserNotification;
use App\Notifications\ReminderDueNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Member reminders: once the day before the due date and once on the due date (app timezone). */
class SendRemindersCommand extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send member reminders the day before and on the due date';

    public function handle(NotificationService $notifications): int
    {
        $tz = (string) config('app.timezone');
        $todayStart = now()->timezone($tz)->startOfDay();
        $tomorrowEnd = $todayStart->copy()->addDays(2);
        $sent = 0;

        Reminder::query()->with('user')
            ->whereNull('notified_due_at')
            ->where('due_at', '>=', $todayStart)
            ->where('due_at', '<', $tomorrowEnd)
            ->chunkById(200, function ($reminders) use (&$sent, $notifications, $tz, $todayStart) {
                foreach ($reminders as $reminder) {
                    $user = $reminder->user;
                    if (! $user || $user->isSuspended()) {
                        continue;
                    }
                    $dueDay = Carbon::parse($reminder->due_at)->timezone($tz)->startOfDay();
                    $when = $dueDay->equalTo($todayStart) ? 'today' : 'tomorrow';
                    if ($when === 'tomorrow' && $reminder->notified_day_before_at !== null) {
                        continue;
                    }

                    $notifications->send($user, UserNotification::REMINDER_DUE, ($when === 'today' ? 'Due today: ' : 'Due tomorrow: ').$reminder->title, $reminder->notes, [
                        'reminder_id' => $reminder->id,
                        'due_at' => $reminder->due_at->toIso8601String(),
                        'conversation_id' => $reminder->conversation_id,
                    ]);
                    $notifications->mail($user, new ReminderDueNotification($reminder, $when));
                    $reminder->forceFill($when === 'today'
                        ? ['notified_due_at' => now(), 'notified_day_before_at' => $reminder->notified_day_before_at ?? now()]
                        : ['notified_day_before_at' => now()])->save();
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder notice(s).");

        return self::SUCCESS;
    }
}
