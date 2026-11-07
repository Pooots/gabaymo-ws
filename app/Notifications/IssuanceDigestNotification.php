<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Daily digest of new or superseding issuances for the member's region. */
class IssuanceDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<array<string, mixed>>  $updates */
    public function __construct(public readonly array $updates) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(count($this->updates) === 1 ? 'New issuance: '.($this->updates[0]['title'] ?? 'DepEd update') : count($this->updates).' new issuances for your region')
            ->line('These issuances were published for your region (or nationally):');
        foreach (array_slice($this->updates, 0, 20) as $update) {
            $line = '- **'.($update['issuance_number'] ?? 'Issuance').'** '.($update['title'] ?? '');
            if (! empty($update['supersedes']['issuance_number'])) {
                $line .= ' (supersedes '.$update['supersedes']['issuance_number'].')';
            }
            $mail->line($line);
        }

        return $mail
            ->action('Ask GabayMo about them', rtrim((string) config('services.frontend_url'), '/').'/ask')
            ->line('You can turn these alerts off in your account settings.');
    }
}
