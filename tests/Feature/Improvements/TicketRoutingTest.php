<?php

namespace Tests\Feature\Improvements;

use App\Models\SupportTicket;
use App\Models\UserNotification;
use App\Notifications\TicketOverdueNotification;
use App\Notifications\TicketReplyNotification;
use Illuminate\Support\Facades\Notification;

/** Practice-area routing, reply deadlines, overdue alerts and closing. */
class TicketRoutingTest extends ImprovementTestCase
{
    private function ticket(int $userId, ?string $area, array $extra = []): SupportTicket
    {
        return SupportTicket::query()->create(['user_id' => $userId, 'subject' => 'About '.($area ?? 'anything'), 'body' => 'b', 'status' => 'open', 'practice_area' => $area, ...$extra]);
    }

    public function test_partners_only_see_tickets_in_their_practice_areas(): void
    {
        $member = $this->member([], $this->paidPlan());
        $leave = $this->ticket($member->id, 'leave');
        $ethics = $this->ticket($member->id, 'ethics');
        $none = $this->ticket($member->id, null);
        $specialist = $this->partner(['practice_areas' => ['leave']]);
        $generalist = $this->partner();

        $ids = collect($this->actingAs($specialist, 'web')->getJson('/api/v1/partner/tickets')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$leave->id, $none->id], $ids);
        $this->assertCount(3, $this->actingAs($generalist, 'web')->getJson('/api/v1/partner/tickets')->json('data'));

        $this->actingAs($specialist, 'web')->postJson("/api/v1/partner/tickets/{$ethics->id}/accept")->assertNotFound();
        $accepted = $this->actingAs($specialist, 'web')->postJson("/api/v1/partner/tickets/{$leave->id}/accept")->assertOk();
        $this->assertTrue(now()->addHours(24)->diffInMinutes($leave->fresh()->reply_due_at, true) < 2);
        $accepted->assertJsonPath('ticket.practice_area', 'leave')->assertJsonPath('ticket.overdue', false);
    }

    public function test_new_ticket_notifies_only_partners_who_take_its_area(): void
    {
        $member = $this->member([], $this->paidPlan());
        $leave = $this->partner(['practice_areas' => ['leave']]);
        $ethics = $this->partner(['practice_areas' => ['ethics']]);

        $this->actingAs($member, 'web')->postJson('/api/v1/tickets', ['subject' => 'Sick leave', 'body' => 'How many days?', 'practice_area' => 'leave'])
            ->assertCreated()->assertJsonPath('ticket.practice_area', 'leave')->assertJsonPath('ticket.closed_at', null);
        $this->actingAs($member, 'web')->postJson('/api/v1/tickets', ['subject' => 'x', 'body' => 'y', 'practice_area' => 'bogus'])->assertStatus(422);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $leave->id, 'type' => UserNotification::TICKET_CREATED]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $ethics->id]);
    }

    public function test_overdue_tickets_are_flagged_and_the_partner_is_notified_once(): void
    {
        Notification::fake();
        $member = $this->member([], $this->paidPlan());
        $partner = $this->partner();
        $ticket = $this->ticket($member->id, null, ['status' => 'accepted', 'partner_id' => $partner->id, 'accepted_at' => now()]);
        $ticket->forceFill(['reply_due_at' => now()->subHour()])->save();
        $admin = $this->staff(['tickets']);

        $this->actingAs($partner, 'web')->getJson('/api/v1/partner/accepted')->assertOk()->assertJsonPath('data.0.overdue', true);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/tickets?status=overdue')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.overdue', true);

        $this->artisan('tickets:overdue')->assertSuccessful();
        $this->artisan('tickets:overdue')->assertSuccessful();
        Notification::assertSentToTimes($partner, TicketOverdueNotification::class, 1);
        $this->assertSame(1, UserNotification::query()->where('user_id', $partner->id)->where('type', UserNotification::TICKET_OVERDUE)->count());

        // The partner replies: nothing is due; the member writes again: a new deadline.
        $this->actingAs($partner, 'web')->postJson("/api/v1/partner/tickets/{$ticket->id}/messages", ['body' => 'Here is the answer'])->assertCreated();
        $this->assertNull($ticket->fresh()->reply_due_at);
        Notification::assertSentTo($member, TicketReplyNotification::class);
        $this->actingAs($member, 'web')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'Thanks, one more thing'])->assertCreated();
        $this->assertTrue($ticket->fresh()->reply_due_at->isFuture());
    }

    public function test_member_partner_and_admin_can_close(): void
    {
        $member = $this->member([], $this->paidPlan());
        $partner = $this->partner();
        $admin = $this->staff(['tickets']);
        $a = $this->ticket($member->id, null);
        $b = $this->ticket($member->id, null, ['status' => 'accepted', 'partner_id' => $partner->id]);
        $c = $this->ticket($member->id, null);
        $other = $this->member();

        $this->actingAs($other, 'web')->postJson("/api/v1/tickets/{$a->id}/close")->assertNotFound();
        $this->actingAs($member, 'web')->postJson("/api/v1/tickets/{$a->id}/close")->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->assertNotNull($a->fresh()->closed_at);

        $this->actingAs($partner, 'web')->postJson("/api/v1/partner/tickets/{$a->id}/close")->assertForbidden();
        $this->actingAs($partner, 'web')->postJson("/api/v1/partner/tickets/{$b->id}/close")->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->actingAs($partner, 'web')->postJson("/api/v1/partner/tickets/{$b->id}/messages", ['body' => 'late'])->assertForbidden();

        $this->actingAs($admin, 'admin')->getJson("/api/v1/admin/tickets/{$c->id}")->assertOk()->assertJsonPath('ticket.id', $c->id);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/tickets/{$c->id}/close")->assertOk()->assertJsonPath('ticket.closed_by', 'admin');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $member->id, 'type' => UserNotification::TICKET_CLOSED]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.close']);
    }
}
