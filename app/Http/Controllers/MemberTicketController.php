<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\Notifications\NotificationService;
use App\Support\PracticeAreas;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberTicketController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request)
    {
        $tickets = SupportTicket::query()
            ->with('partner')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (SupportTicket $ticket) => $this->present($ticket));

        return response()->json(['tickets' => $tickets]);
    }

    public function store(Request $request)
    {
        $plan = $request->user()->subscriptionPlan;
        if (! $plan || (int) $plan->price <= 0) {
            return response()->json([
                'message' => 'Tickets are included with a paid membership.',
            ], 403);
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'practice_area' => ['nullable', 'string', Rule::in(PracticeAreas::KEYS)],
        ]);

        $ticket = SupportTicket::query()->create([
            'user_id' => $request->user()->id,
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'status' => 'open',
            'practice_area' => $data['practice_area'] ?? null,
        ]);

        AdminOverviewController::record(
            $request,
            'ticket.create',
            $request->user()->name.' submitted a ticket: '.$ticket->subject,
        );

        $this->notifications->ticketCreated($ticket);

        return response()->json(['ticket' => $this->present($ticket)], 201);
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        if (! $this->owns($request, $ticket)) {
            return response()->json(['message' => 'This ticket was not found.'], 404);
        }

        return response()->json([
            'ticket' => $this->present($ticket->loadMissing('partner')),
            'messages' => $this->messagesFor($request, $ticket),
        ]);
    }

    public function message(Request $request, SupportTicket $ticket)
    {
        if (! $this->owns($request, $ticket)) {
            return response()->json(['message' => 'This ticket was not found.'], 404);
        }

        if ($ticket->status !== 'accepted') {
            return response()->json([
                'message' => 'A partner has not accepted this ticket yet.',
            ], 422);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);
        // The partner now owes a reply (unless one is already due).
        if ($ticket->reply_due_at === null) {
            $ticket->forceFill([
                'reply_due_at' => now()->addHours((int) config('services.tickets.reply_hours', 24)),
                'overdue_notified_at' => null,
            ])->save();
        }

        $this->notifications->ticketMessage($ticket, $message, $request->user());

        return response()->json([
            'message' => $this->presentMessage($request, $message->load('user')),
        ], 201);
    }

    public function close(Request $request, SupportTicket $ticket)
    {
        if (! $this->owns($request, $ticket)) {
            return response()->json(['message' => 'This ticket was not found.'], 404);
        }
        if ($ticket->isClosed()) {
            return response()->json(['ticket' => $this->present($ticket)]);
        }

        $ticket->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'member', 'reply_due_at' => null])->save();
        AdminOverviewController::record($request, 'ticket.close', $request->user()->name.' closed ticket #'.$ticket->id);
        $this->notifications->ticketClosed($ticket, 'member');

        return response()->json(['ticket' => $this->present($ticket->fresh())]);
    }

    private function owns(Request $request, SupportTicket $ticket): bool
    {
        return (int) $ticket->user_id === (int) $request->user()->id;
    }

    private function messagesFor(Request $request, SupportTicket $ticket): array
    {
        return $ticket->messages()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketMessage $message) => $this->presentMessage($request, $message))
            ->all();
    }

    private function presentMessage(Request $request, TicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            'mine' => (int) $message->user_id === (int) $request->user()->id,
            'sender_name' => $message->user?->isPartner() ? 'Gabaymo Partner' : 'You',
            'sender_role' => $message->user?->isPartner() ? 'partner' : 'member',
        ];
    }

    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'practice_area' => $ticket->practice_area,
            'partner_name' => $ticket->partner_id ? 'Gabaymo Partner' : null,
            'reply_due_at' => $ticket->reply_due_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
