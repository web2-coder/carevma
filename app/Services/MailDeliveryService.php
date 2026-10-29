<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailDeliveryService
{
    /**
     * Send a user-critical message during the request. Transient SMTP rate
     * limits are retried with backoff so invitations/invoices do not depend
     * on a queue worker to reach the recipient.
     *
     * @return array{sent:bool,error:?string}
     */
    public function sendNow(string $recipient, Mailable $mailable, string $purpose = 'CareVMA email'): array
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return ['sent' => false, 'error' => 'Recipient email is missing.'];
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                Mail::to($recipient)->send($mailable);
                return ['sent' => true, 'error' => null];
            } catch (\Throwable $e) {
                if ($attempt < 3 && $this->looksRateLimited($e->getMessage())) {
                    sleep($attempt * 2);
                    continue;
                }

                Log::warning('CareVMA critical mail could not be sent.', [
                    'purpose' => $purpose,
                    'recipient' => $recipient,
                    'mailer' => config('mail.default'),
                    'smtp_host' => config('mail.mailers.smtp.host'),
                    'error' => $e->getMessage(),
                ]);

                return ['sent' => false, 'error' => $e->getMessage()];
            }
        }

        return ['sent' => false, 'error' => 'Email delivery failed after retries.'];
    }

    /**
     * Queue SMTP mail into spaced delivery slots so providers with a low
     * per-second limit do not reject a burst of CareVMA notifications.
     *
     * @return array{queued:bool,delay_seconds:int,error:?string}
     */
    public function queue(string $recipient, Mailable $mailable, string $purpose = 'CareVMA email'): array
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return ['queued' => false, 'delay_seconds' => 0, 'error' => 'Recipient email is missing.'];
        }

        $delaySeconds = $this->reserveDeliverySlot();

        try {
            if (config('queue.default') === 'sync') {
                // A sync queue cannot protect a provider from a burst. Retry a
                // transient provider-rate rejection with a short backoff.
                $attempts = 0;
                do {
                    try {
                        if ($delaySeconds > 0) {
                            usleep($delaySeconds * 1000000);
                        }
                        Mail::to($recipient)->send($mailable);
                        return ['queued' => true, 'delay_seconds' => $delaySeconds, 'error' => null];
                    } catch (\Throwable $e) {
                        $attempts++;
                        if ($attempts >= 3 || !$this->looksRateLimited($e->getMessage())) {
                            throw $e;
                        }
                        sleep($attempts * 2);
                    }
                } while ($attempts < 3);
            }

            Mail::to($recipient)->later(now()->addSeconds($delaySeconds), $mailable);
            return ['queued' => true, 'delay_seconds' => $delaySeconds, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('CareVMA mail could not be queued/sent.', [
                'purpose' => $purpose,
                'recipient' => $recipient,
                'mailer' => config('mail.default'),
                'smtp_host' => config('mail.mailers.smtp.host'),
                'error' => $e->getMessage(),
            ]);

            return ['queued' => false, 'delay_seconds' => $delaySeconds, 'error' => $e->getMessage()];
        }
    }

    private function reserveDeliverySlot(): int
    {
        $bucket = 'carevma:mail-slot:'.now()->format('YmdHi');
        Cache::add($bucket, 0, now()->addMinutes(3));
        $slot = max(1, (int) Cache::increment($bucket));

        // Three seconds between queued messages is deliberately conservative
        // for low-throughput/testing SMTP accounts.
        return min(($slot - 1) * 3, 180);
    }

    private function looksRateLimited(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'too many emails')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, '421')
            || str_contains($message, '450')
            || str_contains($message, '451');
    }
}
