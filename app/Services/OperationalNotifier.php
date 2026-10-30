<?php

namespace App\Services;

use App\Events\CareVmaNotificationBroadcast;
use App\Mail\OperationalActivityMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OperationalNotifier
{
    public function __construct(private readonly MailDeliveryService $mailDelivery)
    {
    }

    /**
     * @param iterable<User>|User $recipients
     */
    public function send(iterable|User $recipients, string $title, string $message, string $type = 'OperationalActivity', ?string $actionUrl = null, array $context = [], bool $sendEmail = true): void
    {
        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
        $users = $users->filter(fn ($user) => $user instanceof User)->unique('id')->values();

        foreach ($users as $user) {
            try {
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => $actionUrl,
                    'data' => array_merge($context, [
                        'message' => $message,
                        'title' => $title,
                        'action_url' => $actionUrl,
                    ]),
                    'is_read' => false,
                ]);

                // Real-time delivery is best-effort. The database notification remains
                // the source of truth even if Pusher is temporarily unavailable.
                try {
                    CareVmaNotificationBroadcast::dispatch($notification);
                } catch (\Throwable $broadcastError) {
                    Log::warning('CareVMA Pusher notification could not be broadcast.', [
                        'notification_id' => $notification->id,
                        'user_id' => $user->id,
                        'error' => $broadcastError->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('CareVMA in-app notification could not be saved.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (!$sendEmail || !$user->email) {
                continue;
            }

            $result = $this->mailDelivery->queue(
                $user->email,
                new OperationalActivityMail(
                    recipient: $user,
                    title: $title,
                    messageText: $message,
                    type: $type,
                    actionUrl: $actionUrl,
                ),
                'operational notification'
            );

            if (!$result['queued']) {
                Log::warning('CareVMA operational email could not be queued.', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $result['error'],
                ]);
            }
        }
    }

    public function operationsUsers(): Collection
    {
        return User::query()->whereIn('role', ['superadmin', 'admin', 'hr'])->get();
    }
}
