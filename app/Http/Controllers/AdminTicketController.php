<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\Notifications\NotificationService;
use App\Support\Paginates;
use Illuminate\Http\Request;

class AdminTicketController extends Controller
{
    use Paginates;

    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request)
    {
        $query = SupportTicket::query()->with('user', 'partner')->latest('id');
        $status = (string) $request->query('status', '');
        if ($status === 'overdue') {
            $query->where('status', 'accepted')->whereNotNull('reply_due_at')->where('reply_due_at', '<', now());
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        if ($area = $request->query('practice_area')) {
            $query->where('practice_area', (string) $area);
        }
        $this->search($request, $query, ['subject']);

        return response()->json($this->paginate($request, $query, fn (SupportTicket $t) => $this->present($t)));
    }

    public function show(SupportTicket $ticket)
    {
        $ticket->loadMissing('user', 'partner');

        return response()->json([
            'ticket' => $this->present($ticket),
            'messages' => $ticket->messages()->with('user')->orderBy('id')->get()->map(fn (TicketMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'sender_name' => $m->user?->name,
                'sender_role' => $m->user?->isPartner() ? 'partner' : 'member',
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function close(Request $request, SupportTicket $ticket)
    {
        if (! $ticket->isClosed()) {
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'admin', 'reply_due_at' => null])->save();
            AdminOverviewController::record($request, 'ticket.close', $request->user()->name.' closed ticket #'.$ticket->id);
            $this->notifications->ticketClosed($ticket, 'admin');
        }

        return response()->json(['ticket' => $this->present($ticket->fresh(['user', 'partner']))]);
    }

    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'practice_area' => $ticket->practice_area,
            'member' => $ticket->user ? ['id' => $ticket->user->id, 'name' => $ticket->user->name, 'email' => $ticket->user->email] : null,
            'partner' => $ticket->partner ? ['id' => $ticket->partner->id, 'name' => $ticket->partner->name] : null,
            'reply_due_at' => $ticket->reply_due_at?->toIso8601String(),
            'overdue' => $ticket->isOverdue(),
            'accepted_at' => $ticket->accepted_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'closed_by' => $ticket->closed_by,
            'from_ai_handoff' => $ticket->ai_question_id !== null,
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
