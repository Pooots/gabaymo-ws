<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeDocument;
use App\Models\ServiceInstruction;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Paginates;
use App\Support\PracticeAreas;
use App\Support\SpaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerController extends Controller
{
    use Paginates;

    public function __construct(private readonly NotificationService $notifications) {}

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
            'open_tickets' => $this->forAreas(SupportTicket::query()->where('status', 'open')->whereNull('partner_id'), $request->user())->count(),
            'overdue_tickets' => SupportTicket::query()->where('partner_id', $request->user()->id)->where('status', 'accepted')
                ->whereNotNull('reply_due_at')->where('reply_due_at', '<', now())->count(),
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

    /** Open, unassigned tickets in the partner's practice areas (or without an area). */
    public function tickets(Request $request)
    {
        $query = $this->forAreas($this->ticketQuery()->where('status', 'open')->whereNull('partner_id'), $request->user())->latest();
        $this->search($request, $query, ['subject']);

        return response()->json($this->paginate($request, $query, fn (SupportTicket $ticket) => $this->presentTicket($ticket), 'tickets'));
    }

    public function accepted(Request $request)
    {
        $query = $this->ticketQuery()
            ->where('partner_id', $request->user()->id)
            ->where('status', 'accepted')
            ->latest('accepted_at');
        $this->search($request, $query, ['subject']);

        return response()->json($this->paginate($request, $query, fn (SupportTicket $ticket) => $this->presentTicket($ticket), 'tickets'));
    }

    public function accept(Request $request, SupportTicket $ticket)
    {
        if (! $this->ticketQuery()->whereKey($ticket->id)->exists() || ! NotificationService::partnerTakes($request->user(), $ticket->practice_area)) {
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
                'reply_due_at' => now()->addHours((int) config('services.tickets.reply_hours', 24)),
                'overdue_notified_at' => null,
            ])->save();
        });

        if ($taken) {
            return response()->json([
                'message' => 'Another partner already accepted this ticket.',
            ], 422);
        }

        $accepted = $ticket->fresh('user.subscriptionPlan');
        AdminOverviewController::record($request, 'ticket.accept', $request->user()->name.' accepted ticket #'.$accepted->id);
        $this->notifications->ticketAccepted($accepted);

        return response()->json([
            'ticket' => $this->presentTicket($accepted),
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
        // Replied: nothing is due until the member writes again.
        $ticket->forceFill(['reply_due_at' => null, 'overdue_notified_at' => null])->save();

        $this->notifications->ticketMessage($ticket, $message, $request->user());

        return response()->json(['message' => $this->presentMessage($request, $message->load('user'))], 201);
    }

    public function close(Request $request, SupportTicket $ticket)
    {
        if (! $this->ownsAccepted($request, $ticket)) {
            return response()->json(['message' => 'This ticket is not in your accepted list.'], 403);
        }

        $ticket->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'partner', 'reply_due_at' => null])->save();
        AdminOverviewController::record($request, 'ticket.close', $request->user()->name.' closed ticket #'.$ticket->id);
        $this->notifications->ticketClosed($ticket, 'partner');

        return response()->json(['ticket' => $this->presentTicket($ticket->fresh('user.subscriptionPlan'))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'mobile' => ['required', 'string', 'regex:/^(?:09\d{9}|\+639\d{9}|639\d{9})$/'],
            'specialty' => ['required', 'string', 'max:160'],
            'response_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'practice_areas' => ['sometimes', 'array'],
            'practice_areas.*' => ['string', Rule::in(PracticeAreas::KEYS)],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'photo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
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
            ...(array_key_exists('practice_areas', $data) ? ['practice_areas' => array_values(array_unique($data['practice_areas']))] : []),
            ...collect(['title', 'bio', 'photo_url'])
                ->filter(fn (string $field) => array_key_exists($field, $data))
                ->mapWithKeys(fn (string $field) => [$field => trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null])
                ->all(),
        ])->save();
        AdminOverviewController::record($request, 'partner.profile', $user->name.' updated their profile');

        return response()->json(['partner' => $this->present($user->fresh())]);
    }

    public function logout(Request $request)
    {
        AdminOverviewController::record($request, 'partner.logout', $request->user()->name.' signed out');
        SpaSession::logout($request, 'web');

        return response()->json(['ok' => true]);
    }

    private function ticketQuery()
    {
        return SupportTicket::query()
            ->with('user.subscriptionPlan')
            ->whereHas('user', fn ($query) => $query->customers());
    }

    private function forAreas($query, User $partner)
    {
        $areas = array_values(array_filter((array) ($partner->practice_areas ?? [])));
        if ($areas === []) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull('practice_area')->orWhereIn('practice_area', $areas));
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
            'practice_area' => $ticket->practice_area,
            'reply_due_at' => $ticket->reply_due_at?->toIso8601String(),
            'overdue' => $ticket->isOverdue(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
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
            'practice_areas' => array_values((array) ($user->practice_areas ?? [])),
            'title' => $user->title,
            'bio' => $user->bio,
            'photo_url' => $user->photo_url,
        ];
    }
}
