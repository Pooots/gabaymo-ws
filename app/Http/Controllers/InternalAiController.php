<?php

namespace App\Http\Controllers;

use App\Models\AiCreditReservation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Billing\TokenMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Endpoints gabaymo-ai calls from its queue (signed; see VerifyAiServiceSignature). */
class InternalAiController extends Controller
{
    public function __construct(private readonly TokenMeter $credits) {}

    public function finalize(Request $request, string $reservation)
    {
        $data = $request->validate(['question_id' => ['required', 'uuid'], 'usage' => ['nullable', 'array']]);
        if (! AiCreditReservation::query()->whereKey($reservation)->exists()) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }
        $row = $this->credits->finalize($reservation, $data['question_id'], (array) ($data['usage'] ?? []));

        return response()->json(['ok' => true, 'status' => $row->status]);
    }

    public function release(Request $request, string $reservation)
    {
        $data = $request->validate(['question_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:120']]);
        if (! AiCreditReservation::query()->whereKey($reservation)->exists()) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }
        $row = $this->credits->release($reservation, $data['question_id'], (string) ($data['reason'] ?? 'released'));

        return response()->json(['ok' => true, 'status' => $row->status]);
    }

    public function handoffTicket(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'question_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $user = User::query()->customers()->find($data['user_id']);
        if (! $user) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $ticket = DB::transaction(fn () => SupportTicket::query()->firstOrCreate(
            ['ai_question_id' => $data['question_id']],
            ['user_id' => $user->id, 'subject' => $data['subject'], 'body' => $data['body'], 'status' => 'open'],
        ));

        if ($ticket->wasRecentlyCreated) {
            app(NotificationService::class)->ticketCreated($ticket);
        }

        return response()->json(['ok' => true, 'ticket' => ['id' => $ticket->id, 'status' => $ticket->status]], $ticket->wasRecentlyCreated ? 201 : 200);
    }
}
