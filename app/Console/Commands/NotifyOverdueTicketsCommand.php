<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class NotifyOverdueTicketsCommand extends Command
{
    protected $signature = 'tickets:overdue';

    protected $description = 'Notify assigned partners whose ticket reply is past due (once per deadline)';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        SupportTicket::query()->with('partner')
            ->where('status', 'accepted')
            ->whereNotNull('partner_id')
            ->whereNotNull('reply_due_at')
            ->where('reply_due_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->chunkById(200, function ($tickets) use (&$sent, $notifications) {
                foreach ($tickets as $ticket) {
                    $notifications->ticketOverdue($ticket);
                    $ticket->forceFill(['overdue_notified_at' => now()])->save();
                    $sent++;
                }
            });
        $this->info("Notified {$sent} overdue ticket(s).");

        return self::SUCCESS;
    }
}
