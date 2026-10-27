<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use App\Events\Notifications;
use App\Models\Notification;
class NotificationController extends Controller
{
    // Fetch notifications for index page
    public function index()
    {
        // Get latest 20 notifications
        $notifications = DatabaseNotification::latest()->get()->reverse();

        return view('admin.notifications.index', compact('notifications'));
    }

    // Fetch notifications as JSON (for dropdown / AJAX)
    public function fetchAll()
    {
        $notifications = DatabaseNotification::latest()->take(10)->get();
        return response()->json($notifications);
    }

    // Mark notification as read
    public function markAsRead($id)
    {
        $notification = Notification::findOrFail($id);

        $notification->update([
            'is_read' => 1
        ]);

        return redirect()->back()->with('success', 'Notification marked as read.');
    }
    public function markAllAsRead()
    {
        Notification::where('is_read', 0)->update([
            'is_read' => 1,
            'updated_at' => now()
        ]);

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }

    public function readSingle(Request $request)
    {
        $notification = DatabaseNotification::find($request->id);
        if ($notification) {
            $notification->is_read = 1;
            $notification->save();
        }
        return response()->json(['success' => true]);
    }


}