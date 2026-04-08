<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        $notifications = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function unread(): JsonResponse
    {
        $user = auth()->user();

        // Proactively manage auction statuses to ensure "automatic" behavior
        // even if the console scheduler is not running.
        $auctionService = new \App\Services\AuctionService();
        $auctionService->activatePending();

        $now = now();
        $expiredAuctionsCount = \App\Models\Auction::whereIn('status', ['active', 'pending'])
            ->where('ends_at', '<=', $now)
            ->count();

        if ($expiredAuctionsCount > 0) {
            $expiredAuctions = \App\Models\Auction::whereIn('status', ['active', 'pending'])
                ->where('ends_at', '<=', $now)
                ->get();

            foreach ($expiredAuctions as $auction) {
                $auctionService->endAuction($auction);
            }
        }

        $notifications = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'count' => $notifications->count(),
        ]);
    }

    public function markAsRead(int $id): JsonResponse
    {
        $user = auth()->user();

        $notification = Notification::where('user_id', $user->id)->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notificación marcada como leída',
            'notification' => $notification,
        ]);
    }

    public function markAllAsRead(): JsonResponse
    {
        $user = auth()->user();

        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'Todas las notificaciones marcadas como leídas']);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        $notification = Notification::where('user_id', $user->id)->findOrFail($id);

        $notification->delete();

        return response()->json(['message' => 'Notificación eliminada']);
    }

    public function clear(): JsonResponse
    {
        $user = auth()->user();

        Notification::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Notificaciones eliminadas']);
    }
}
