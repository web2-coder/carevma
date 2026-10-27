<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use App\Models\Notification;


class Notifications implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $data;
    public $type;

    /**
     * @param array $data Data to store in JSON (e.g., user info, profile info, order info)
     * @param string $type Type of notification (e.g., UserRegistered, ProfileUpdated)
     */
    public function __construct(array $data, string $type)
    {
        $this->data = $data;
        $this->type = $type;

        // Store in database
        Notification::create([
            'data' => $data,
            'type' => $type,
            'is_read' => false,
        ]);
    }

    public function broadcastOn()
    {
        return new Channel(strtolower(str_replace(' ', '-', $this->type)));
    }

    public function broadcastAs()
    {
        // custom event name
        return $this->type . 'Notification';
    }
    public function count()
    {
        $count = Notification::where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }

}