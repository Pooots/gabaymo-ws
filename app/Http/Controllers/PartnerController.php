<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeDocument;
use App\Models\ServiceInstruction;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerController extends Controller
{
    public function me(Request $request)
    {
        return response()->json(['partner' => $this->present($request->user())]);
    }

    public function dashboard(Request $request)
    {
        $approved = KnowledgeDocument::query()
            ->where('active', true)
            ->where('source_status', 'approved');

        return response()->json([
            'partner' => $this->present($request->user()),
            'open_tickets' => SupportTicket::query()->where('status', 'open')->whereNull('partner_id')->count(),
            'instructions' => ServiceInstruction::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ServiceInstruction $instruction) => [
                    'id' => $instruction->id,
                    'title' => $instruction->title,
                ]),
            'documents' => (clone $approved)->count(),
            'sources' => (clone $approved)
                ->latest('updated_at')
                ->limit(4)
                ->get()
                ->map(fn (KnowledgeDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'reference' => $document->reference,
                ]),
        ]);
    }

    public function instructions()
    {
        return response()->json([
            'instructions' => ServiceInstruction::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ServiceInstruction $instruction) => [
                    'id' => $instruction->id,
                    'title' => $instruction->title,
                    'body' => $instruction->body,
                ]),
        ]);
    }

    public function knowledge()
    {
        return response()->json([
            'documents' => KnowledgeDocument::query()
                ->where('active', true)
                ->where('source_status', 'approved')
                ->latest('updated_at')
                ->get()
                ->map(fn (KnowledgeDocument $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'category' => $document->category,
                    'kind_label' => KnowledgeDocument::KINDS[$document->kind] ?? $document->kind,
                    'reference' => $document->reference,
                    'summary' => $document->summary,
                    'content' => $document->content,
                ]),
        ]);
    }

    public function tickets()
    {
        return response()->json([
            'tickets' => $this->ticketQuery()
                ->where('status', 'open')
                ->whereNull('partner_id')
                ->latest()
                ->get()
                ->map(fn (SupportTicket $ticket) => $this->presentTicket($ticket)),
        ]);
    }

    public function accepted(Request $request)
    {
        return response()->json([
            'tickets' => $this->ticketQuery()
                ->where('partner_id', $request->user()->id)
                ->where('status', 'accepted')
                ->latest('accepted_at')
                ->get()
                ->map(fn (SupportTicket $ticket) => $this->presentTicket($ticket)),
        ]);
    }

    public function accept(Request $request, SupportTicket $ticket)
    {
        if (! $this->ticketQuery()->whereKey($ticket->id)->exists()) {
            return response()->json(['message' => 'This ticket was not found.'], 404);
        }

        $taken = false;

        DB::transaction(function () use ($request, $ticket, &$taken) {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'open' || $locked->partner_id) {
                $taken = true;

                return;
            }

            $locked->forceFill([
                'partner_id' => $request->user()->id,
                'status' => 'accepted',
                'accepted_at' => now(),
            ])->save();
        });

        if ($taken) {
            return response()->json([
                'message' => 'Another partner already accepted this ticket.',
            ], 422);
        }

        return response()->json([
            'ticket' => $this->presentTicket($ticket->fresh('user.subscriptionPlan')),
        ]);
    }

    public function messages(Request $request, SupportTicket $ticket)
    {
        if (! $this->ownsAccepted($request, $ticket)) {
            return response()->json(['message' => 'This ticket is not in your accepted list.'], 403);
        }

        return response()->json([
            'ticket' => $this->presentTicket($ticket->loadMissing('user.subscriptionPlan')),
            'messages' => $this->presentMessages($request, $ticket),
        ]);
    }

    public function storeMessage(Request $request, SupportTicket $ticket)
    {
        if (! $this->ownsAccepted($request, $ticket)) {
            return response()->json(['message' => 'This ticket is not in your accepted list.'], 403);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);

        return response()->json(['message' => $this->presentMessage($request, $message->load('user'))], 201);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'mobile' => ['required', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'specialty' => ['required', 'string', 'max:160'],
            'response_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ], [
            'mobile.regex' => 'Enter a Philippine mobile number, such as 09171234567.',
        ]);

        $user = $request->user();
        $first = trim($data['first_name']);
        $last = trim($data['last_name']);
        $digits = preg_replace('/\D+/', '', $data['mobile']) ?? '';
        if (str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        $user->forceFill([
            'name' => trim($first.' '.$last),
            'first_name' => $first,
            'last_name' => $last,
            'mobile' => $digits,
            'specialty' => trim($data['specialty']),
            'response_hours' => $data['response_hours'],
        ])->save();

        return response()->json(['partner' => $this->present($user->fresh())]);
    }

    public function logout(Request $request)
    {
        SpaSession::logout($request, 'web');

        return response()->json(['ok' => true]);
    }

    private function ticketQuery()
    {
        return SupportTicket::query()
            ->with('user.subscriptionPlan')
            ->whereHas('user', fn ($query) => $query->customers());
    }

    private function ownsAccepted(Request $request, SupportTicket $ticket): bool
    {
        return $ticket->status === 'accepted' && (int) $ticket->partner_id === (int) $request->user()->id;
    }

    private function presentTicket(SupportTicket $ticket): array
    {
        $member = $ticket->user;

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'body' => $ticket->body,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'accepted_at' => $ticket->accepted_at?->toIso8601String(),
            'member_name' => $member?->name ?? 'Member',
            'member_email' => $member?->email,
            'member_region' => $member?->region,
            'member_plan' => $member?->subscriptionPlan?->name ?? 'Free Trial',
        ];
    }

    private function presentMessages(Request $request, SupportTicket $ticket): array
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
            'sender_name' => $message->user?->name ?? 'Member',
            'sender_role' => $message->user?->isPartner() ? 'partner' : 'member',
        ];
    }

    private function present(User $user): array
    {
        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'specialty' => $user->specialty,
            'account_type' => $user->account_type,
            'response_hours' => $user->response_hours,
            'partner_status' => $user->partner_status,
        ];
    }
}
