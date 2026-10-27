<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class OperationalActivityMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public string $title,
        public string $messageText,
        public string $type = 'OperationalActivity',
        public ?string $actionUrl = null,
    ) {}

    public function build(): self
    {
        $resolvedUrl = $this->resolveActionUrl($this->actionUrl);

        return $this->subject('[CareVMA] '.$this->title)
            ->view('emails.operational_activity')
            ->with([
                'recipientName' => $this->recipient->name
                    ?: trim(($this->recipient->first_name ?? '').' '.($this->recipient->last_name ?? '')),
                'resolvedActionUrl' => $resolvedUrl,
                'actionLabel' => $this->actionLabel($resolvedUrl),
            ]);
    }

    private function resolveActionUrl(?string $actionUrl): ?string
    {
        if (!$actionUrl) {
            return null;
        }

        if (Str::startsWith($actionUrl, ['http://', 'https://'])) {
            return $actionUrl;
        }

        return url('/'.ltrim($actionUrl, '/'));
    }

    private function actionLabel(?string $url): string
    {
        $haystack = strtolower(($this->type ?? '').' '.($this->title ?? '').' '.($url ?? ''));

        return match (true) {
            str_contains($haystack, 'invoice') => 'View Invoice',
            str_contains($haystack, 'change-request'), str_contains($haystack, 'attendance request') => 'Review Request',
            str_contains($haystack, 'overtime') => 'Review Overtime',
            str_contains($haystack, 'schedule') => 'View Schedule',
            str_contains($haystack, 'attendance') || str_contains($haystack, 'clock') || str_contains($haystack, 'break') => 'View Attendance',
            str_contains($haystack, 'employee') || str_contains($haystack, 'profile') => 'View Profile',
            str_contains($haystack, 'client') || str_contains($haystack, 'doctor') => 'View Client',
            str_contains($haystack, 'notification') => 'View Notifications',
            default => 'Open CareVMA',
        };
    }
}
