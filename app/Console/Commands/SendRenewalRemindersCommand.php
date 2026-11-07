<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\RenewalReminderNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/** PayMongo payments are one-time, so members are reminded to pay again 7 days and 1 day before the end. */
class SendRenewalRemindersCommand extends Command
{
    protected $signature = 'subscriptions:remind';

    protected $description = 'Email renewal reminders 7 days and 1 day before a paid period ends';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        User::query()->customers()
            ->with('subscriptionPlan')
            ->whereIn('plan_source', ['personal', 'admin', 'access_key'])
            ->where('cancel_at_period_end', false)
            ->whereNull('suspended_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', now())
            ->where('current_period_end', '<=', now()->addDays(7))
            ->chunkById(200, function ($users) use (&$sent, $notifications) {
                foreach ($users as $user) {
                    if ((int) ($user->subscriptionPlan?->price ?? 0) <= 0) {
                        continue;
                    }
                    $oneDay = $user->current_period_end->lte(now()->addDay());
                    $column = $oneDay ? 'renewal_reminded_1d_at' : 'renewal_reminded_7d_at';
                    if ($user->{$column} !== null) {
                        continue;
                    }
                    $days = $oneDay ? 1 : 7;
                    $plan = $user->subscriptionPlan->name;
                    $endsOn = $user->current_period_end->timezone(config('app.timezone'))->format('F j, Y');
                    $notifications->mail($user, new RenewalReminderNotification($days, $plan, $endsOn));
                    $notifications->send($user, UserNotification::SUBSCRIPTION_RENEWAL, "Your {$plan} ends ".($oneDay ? 'tomorrow' : "on {$endsOn}"), 'Renew to keep your usage and partner support.', ['days' => $days]);
                    // The 1-day notice also covers the 7-day one when the member renewed late.
                    $user->forceFill([$column => now(), 'renewal_reminded_7d_at' => $user->renewal_reminded_7d_at ?? now()])->save();
                    $sent++;
                }
            });
        $this->info("Sent {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }
}
