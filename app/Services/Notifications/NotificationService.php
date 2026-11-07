<?php

namespace App\Services\Notifications;

use App\Events\UserNotificationCreated;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\TicketOverdueNotification;
use App\Notifications\TicketReplyNotification;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Store a notification and push it over Reverb. A down websocket server
     * must never fail the action that caused the notification.
     */
    public function send(User $user, string $type, string $title, ?string $body = null, array $data = []): UserNotification
    {
        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);

        try {
            UserNotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            report($e);
        }

        return $notification;
    }

    /** A member opened a ticket: tell every active partner who takes its practice area. */
    public function ticketCreated(SupportTicket $ticket): void
    {
        $partners = User::query()
            ->partners()
            ->where('partner_status', 'active')
            ->whereNull('suspended_at')
            ->get()
            ->filter(fn (User $partner) => self::partnerTakes($partner, $ticket->practice_area));

        foreach ($partners as $partner) {
            $this->send(
                $partner,
                UserNotification::TICKET_CREATED,
                'New ticket: '.Str::limit($ticket->subject, 80),
                Str::limit($ticket->body, 140),
                ['ticket_id' => $ticket->id],
            );
        }
    }

    /** A partner took the ticket: tell the member their chat is open. */
    public function ticketAccepted(SupportTicket $ticket): void
    {
        $member = $ticket->user;
        if (! $member) {
            return;
        }

        $this->send(
            $member,
            UserNotification::TICKET_ACCEPTED,
            'A partner accepted your ticket',
            Str::limit($ticket->subject, 140),
            ['ticket_id' => $ticket->id],
        );
    }

    /** A chat message: notify the other side of the ticket. */
    public function ticketMessage(SupportTicket $ticket, TicketMessage $message, User $sender): void
    {
        if ($sender->isPartner()) {
            $recipient = $ticket->user;
            $title = 'New reply from your partner';
        } else {
            $recipient = $ticket->partner;
            $title = 'New message from '.($sender->name ?: 'a member');
        }

        if (! $recipient || (int) $recipient->id === (int) $sender->id) {
            return;
        }

        $this->send(
            $recipient,
            UserNotification::TICKET_MESSAGE,
            $title,
            Str::limit($message->body, 140),
            ['ticket_id' => $ticket->id, 'subject' => Str::limit($ticket->subject, 80)],
        );

        if ($sender->isPartner()) {
            $this->mail($recipient, new TicketReplyNotification($ticket, (string) $message->body));
        }
    }

    public function ticketClosed(SupportTicket $ticket, string $by): void
    {
        $recipients = match ($by) {
            'member' => [$ticket->partner],
            'partner' => [$ticket->user],
            default => [$ticket->user, $ticket->partner],
        };
        foreach (array_filter($recipients) as $recipient) {
            $this->send(
                $recipient,
                UserNotification::TICKET_CLOSED,
                'Ticket closed: '.Str::limit($ticket->subject, 80),
                null,
                ['ticket_id' => $ticket->id, 'closed_by' => $by],
            );
        }
    }

    /** The assigned partner is past the reply deadline. */
    public function ticketOverdue(SupportTicket $ticket): void
    {
        $partner = $ticket->partner;
        if (! $partner) {
            return;
        }
        $this->send(
            $partner,
            UserNotification::TICKET_OVERDUE,
            'Reply overdue: '.Str::limit($ticket->subject, 80),
            'A member is waiting for your reply.',
            ['ticket_id' => $ticket->id, 'reply_due_at' => $ticket->reply_due_at?->toIso8601String()],
        );
        $this->mail($partner, new TicketOverdueNotification($ticket));
    }

    /** Empty practice areas mean the partner takes every area; tickets without an area go to everyone. */
    public static function partnerTakes(User $partner, ?string $area): bool
    {
        $areas = array_values(array_filter((array) ($partner->practice_areas ?? [])));

        return $area === null || $area === '' || $areas === [] || in_array($area, $areas, true);
    }

    /** Queue an email; a mail outage must never fail the action that caused it. */
    public function mail(User $user, \Illuminate\Notifications\Notification $notification): void
    {
        if (! $user->email || $user->trashed()) {
            return;
        }
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
