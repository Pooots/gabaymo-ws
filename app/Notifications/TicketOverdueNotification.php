<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Email to the assigned partner when a reply is past due. */
class TicketOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reply overdue: '.Str::limit($this->ticket->subject, 60))
            ->line('A member is waiting for your reply on "'.Str::limit($this->ticket->subject, 120).'".')
            ->line('The reply was due '.$this->ticket->reply_due_at?->timezone(config('app.timezone'))->format('F j, g:i A').'.')
            ->action('Open the ticket', rtrim((string) config('services.partner_url'), '/').'/partner/tickets/'.$this->ticket->id);
    }
}
