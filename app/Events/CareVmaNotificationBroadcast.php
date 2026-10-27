<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CareVmaNotificationBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;
    public int $userId;

    public function __construct(Notification $notification)
    {
        $this->userId = (int) $notification->user_id;
        $this->payload = [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'is_read' => (bool) $notification->is_read,
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'unread_count' => Notification::query()
                ->where('user_id', $notification->user_id)
                ->where('is_read', false)
                ->count(),
        ];
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('carevma.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'carevma.notification';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
