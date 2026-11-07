<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Email to the member when the partner replies on their ticket. */
class TicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket, public readonly string $excerpt) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New reply on your ticket: '.Str::limit($this->ticket->subject, 60))
            ->line('Your GabayMo partner replied:')
            ->line('> '.Str::limit($this->excerpt, 300))
            ->action('Read the reply', rtrim((string) config('services.frontend_url'), '/').'/tickets/'.$this->ticket->id);
    }
}
