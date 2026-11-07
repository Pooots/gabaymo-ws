<?php

namespace App\Mail;

use App\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/** "Set up your account" link for a partner or staff member (see AccountInvitations). */
class AccountInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $name,
        public string $url,
        public string $role,
        public ?string $invitedBy,
        public Carbon $expiresAt,
        public string $purpose = AccountInvitation::SETUP,
    ) {}

    public function envelope(): Envelope
    {
        $app = (string) config('app.name', 'GabayMo');

        return new Envelope(subject: $this->purpose === AccountInvitation::RESET
            ? "Set a new password for your {$app} account"
            : "You're invited to {$app}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-invitation', with: [
            'name' => $this->name,
            'url' => $this->url,
            'role' => $this->role,
            'invitedBy' => $this->invitedBy ?: (string) config('app.name', 'GabayMo'),
            'expires' => $this->expiresAt->copy()->timezone((string) config('app.timezone'))->format('F j, Y g:i A T'),
            'reset' => $this->purpose === AccountInvitation::RESET,
        ]);
    }
}
