<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    public function index(Request $request)
    {
        $limit = min(max((int) $request->query('per_page', 30), 1), 100);
        $query = Notification::query()->where(function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
            if (in_array($request->user()->role, ['superadmin', 'admin', 'hr'], true)) {
                $q->orWhereNull('user_id'); // preserve visibility of legacy global operations notifications
            }
        })->latest('id');

        return response()->json($query->paginate($limit));
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'count' => Notification::where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
                if (in_array($request->user()->role, ['superadmin', 'admin', 'hr'], true)) $q->orWhereNull('user_id');
            })->where('is_read', false)->count(),
        ]);
    }

    public function read(Request $request, Notification $notification)
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        $notification->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAll(Request $request)
    {
        Notification::where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
                if (in_array($request->user()->role, ['superadmin', 'admin', 'hr'], true)) $q->orWhereNull('user_id');
            })
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
