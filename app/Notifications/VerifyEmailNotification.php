<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function url(object $notifiable): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHours(24), [
            'id' => $notifiable->getKey(),
            'hash' => sha1((string) $notifiable->email),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your GabayMo email address')
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).'!')
            ->line('Please confirm that this is your email address so we can send you receipts, reminders and account notices.')
            ->action('Confirm email address', self::url($notifiable))
            ->line('This link expires in 24 hours. If you did not create a GabayMo account, you can ignore this email.');
    }
}
