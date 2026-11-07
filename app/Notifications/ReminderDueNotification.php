<?php

namespace App\Notifications;

use App\Models\Reminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReminderDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'tomorrow'|'today'  $when */
    public function __construct(public readonly Reminder $reminder, public readonly string $when) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->reminder->due_at->timezone(config('app.timezone'))->format('F j, Y g:i A');
        $mail = (new MailMessage)
            ->subject('Reminder: '.$this->reminder->title.($this->when === 'today' ? ' (today)' : ' (tomorrow)'))
            ->line('**'.$this->reminder->title.'** is due '.$this->when.', '.$due.'.');
        if ($this->reminder->notes) {
            $mail->line($this->reminder->notes);
        }

        return $mail->action('Open GabayMo', rtrim((string) config('services.frontend_url'), '/').'/reminders');
    }
}
