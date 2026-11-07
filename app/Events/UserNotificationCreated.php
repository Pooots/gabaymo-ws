<?php

namespace App\Events;

use App\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over Reverb to the recipient's private channel so the bell and the
 * popup update without a refresh. Sent immediately (no queue worker needed).
 */
class UserNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public UserNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->notification->user_id))];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return ['notification' => $this->notification->present()];
    }

    /** Channel name without the "private-" prefix (Echo adds it). */
    public static function channelFor(int $userId): string
    {
        return 'notifications.user.'.$userId;
    }
}
