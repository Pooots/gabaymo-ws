<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenewalReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $days, public readonly string $plan, public readonly string $endsOn) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->days <= 1 ? 'tomorrow' : "in {$this->days} days";

        return (new MailMessage)
            ->subject("Your GabayMo {$this->plan} ends {$when}")
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).'!')
            ->line("Your {$this->plan} ends on {$this->endsOn}. Payments are one-time, so it will not renew by itself.")
            ->line('Renew before then to keep your monthly usage and partner lawyer support without a break.')
            ->action('Renew membership', rtrim((string) config('services.frontend_url'), '/').'/account?renew=1');
    }
}
