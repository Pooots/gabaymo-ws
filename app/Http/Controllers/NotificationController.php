<?php

namespace App\Http\Controllers;

use App\Events\UserNotificationCreated;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        $notifications = UserNotification::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (UserNotification $notification) => $notification->present());

        return response()->json([
            'channel' => UserNotificationCreated::channelFor($userId),
            'unread_count' => UserNotification::query()
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count(),
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, int $notification)
    {
        UserNotification::query()
            ->whereKey($notification)
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Reverb private-channel authorization. Runs the callbacks in
     * routes/channels.php against the session user.
     */
    public function broadcastingAuth(Request $request)
    {
        return Broadcast::auth($request);
    }
}
