<?php

namespace Tests\Feature;

use App\Events\UserNotificationCreated;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * In-app notifications for the ticket desk (bell + Reverb popup).
 * Runs on in-memory SQLite so it never touches the development database.
 */
class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase()
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'broadcasting.default' => 'null',
        ]);
        DB::purge();
    }

    public function test_new_ticket_notifies_active_partners_only(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $member = $this->member();
        $partner = $this->partner('active@test.ph');
        $pending = $this->partner('pending@test.ph', 'pending');

        $this->actingAs($member, 'web')
            ->postJson('/api/v1/tickets', ['subject' => 'What is MOOE?', 'body' => 'Please explain.'])
            ->assertCreated();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $partner->id,
            'type' => UserNotification::TICKET_CREATED,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $pending->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $member->id]);
        Event::assertDispatched(UserNotificationCreated::class, 1);
    }

    public function test_accept_and_replies_notify_the_other_side(): void
    {
        $member = $this->member();
        $partner = $this->partner('active@test.ph');

        $ticketId = $this->actingAs($member, 'web')
            ->postJson('/api/v1/tickets', ['subject' => 'What is MOOE?', 'body' => 'Please explain.'])
            ->json('ticket.id');

        $this->actingAs($partner, 'web')->postJson("/api/v1/partner/tickets/{$ticketId}/accept")->assertOk();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $member->id,
            'type' => UserNotification::TICKET_ACCEPTED,
        ]);

        $this->actingAs($partner, 'web')
            ->postJson("/api/v1/partner/tickets/{$ticketId}/messages", ['body' => 'MOOE means...'])
            ->assertCreated();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $member->id,
            'type' => UserNotification::TICKET_MESSAGE,
            'title' => 'New reply from your partner',
        ]);

        $this->actingAs($member, 'web')
            ->postJson("/api/v1/tickets/{$ticketId}/messages", ['body' => 'Thanks!'])
            ->assertCreated();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $partner->id,
            'type' => UserNotification::TICKET_MESSAGE,
        ]);
    }

    public function test_feed_unread_count_and_mark_read(): void
    {
        $member = $this->member();
        $other = $this->member('other@test.ph');
        $mine = UserNotification::query()->create(['user_id' => $member->id, 'type' => 'ticket.accepted', 'title' => 'A']);
        UserNotification::query()->create(['user_id' => $member->id, 'type' => 'ticket.message', 'title' => 'B']);
        $theirs = UserNotification::query()->create(['user_id' => $other->id, 'type' => 'ticket.message', 'title' => 'C']);

        $this->actingAs($member, 'web')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('channel', 'notifications.user.'.$member->id)
            ->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'notifications');

        // Cannot mark someone else's notification.
        $this->actingAs($member, 'web')->postJson("/api/v1/notifications/{$theirs->id}/read")->assertOk();
        $this->assertNull($theirs->fresh()->read_at);

        $this->actingAs($member, 'web')->postJson("/api/v1/notifications/{$mine->id}/read")->assertOk();
        $this->actingAs($member, 'web')->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 1);

        $this->actingAs($member, 'web')->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->actingAs($member, 'web')->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_private_channel_is_only_for_its_owner(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        $member = $this->member();
        $other = $this->member('other@test.ph');

        $this->actingAs($member, 'web')->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-notifications.user.'.$member->id,
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->actingAs($member, 'web')->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-notifications.user.'.$other->id,
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-notifications.user.'.$member->id,
        ])->assertUnauthorized();
    }

    private function member(string $email = 'member@test.ph'): User
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Pro',
            'price' => 499,
            'cadence' => 'month',
            'summary' => 'Paid',
            'specs' => [],
        ]);

        return User::query()->forceCreate([
            'name' => 'Maria Santos',
            'email' => $email,
            'password' => 'secret123',
            'account_type' => User::CUSTOMER,
            'subscription_plan_id' => $plan->id,
        ]);
    }

    private function partner(string $email, string $status = 'active'): User
    {
        $user = User::query()->forceCreate([
            'name' => 'Atty. Reyes',
            'email' => $email,
            'password' => 'secret123',
            'account_type' => User::PARTNER,
        ]);
        $user->forceFill(['partner_status' => $status])->save();

        return $user;
    }
}
