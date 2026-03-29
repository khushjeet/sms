<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'type' => 'nullable|string|max:50',
            'status' => 'nullable|in:read,unread,all',
        ]);

        $query = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($validated['type'])) {
            $query->where('type', trim((string) $validated['type']));
        }

        $status = $validated['status'] ?? 'all';
        if ($status === 'read') {
            $query->where('is_read', true);
        } elseif ($status === 'unread') {
            $query->where('is_read', false);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 20));
        $page->getCollection()->transform(fn (UserNotification $notification) => $this->transform($notification));

        return response()->json($page);
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $base = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->where('is_read', false);

        $grouped = (clone $base)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return response()->json([
            'total' => (int) $base->count(),
            'by_type' => $grouped->map(fn ($count) => (int) $count),
        ]);
    }

    public function recent(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $items = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? 5))
            ->get()
            ->map(fn (UserNotification $notification) => $this->transform($notification))
            ->values();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $notification = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->findOrFail($id);

        if (!$notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => now(),
            ])->save();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $this->transform($notification->fresh()),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');

        $updated = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated_count' => $updated,
        ]);
    }

    private function transform(UserNotification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => $notification->type,
            'priority' => $notification->priority,
            'entity_type' => $notification->entity_type,
            'entity_id' => $notification->entity_id !== null ? (int) $notification->entity_id : null,
            'action_target' => $notification->action_target,
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
            'meta' => $notification->meta,
        ];
    }
}
