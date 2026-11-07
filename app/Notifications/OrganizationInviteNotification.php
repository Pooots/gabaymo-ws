<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->organization->name.' added you to GabayMo')
            ->line($this->organization->name.' has a GabayMo group membership with a seat for you.')
            ->line('Create your account with this email address and the membership is applied automatically.')
            ->action('Create my account', rtrim((string) config('services.frontend_url'), '/').'/register');
    }
}
