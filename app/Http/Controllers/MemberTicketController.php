<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;

class MemberTicketController extends Controller
{
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
        ]);

        $ticket = SupportTicket::query()->create([
            'user_id' => $request->user()->id,
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'status' => 'open',
        ]);

        AdminOverviewController::record(
            $request,
            'ticket.create',
            $request->user()->name.' submitted a ticket: '.$ticket->subject,
        );

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

        return response()->json([
            'message' => $this->presentMessage($request, $message->load('user')),
        ], 201);
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
            'sender_name' => ($message->user?->role === 'partner') ? 'Gabaymo Partner' : 'You',
            'sender_role' => $message->user?->role === 'partner' ? 'partner' : 'member',
        ];
    }

    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'partner_name' => $ticket->partner_id ? 'Gabaymo Partner' : null,
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
