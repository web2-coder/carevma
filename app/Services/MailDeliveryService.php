<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailDeliveryService
{
    /**
     * Send a user-critical message during the request.
     *
     * CareVMA deliberately reserves a provider delivery slot before every
     * synchronous SMTP send. The reservation is filesystem based instead of
     * Laravel Cache so mail delivery is not coupled to the database cache
     * connection. This protects low-throughput SMTP/testing accounts from
     * bursts such as Mailtrap's "Too many emails per second" response.
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
                $delaySeconds = $this->reserveDeliverySlot();
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }

                Mail::to($recipient)->send($mailable);
                return ['sent' => true, 'error' => null];
            } catch (\Throwable $e) {
                if ($attempt < 3 && $this->looksRateLimited($e->getMessage())) {
                    // Reserve a brand-new provider slot before the next attempt.
                    continue;
                }

                Log::warning('CareVMA critical mail could not be sent.', [
                    'purpose' => $purpose,
                    'recipient' => $recipient,
                    'mailer' => config('mail.default'),
                    'smtp_host' => config('mail.mailers.smtp.host'),
                    'error' => $e->getMessage(),
                ]);

                return ['sent' => false, 'error' => $this->friendlyError($e->getMessage())];
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
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }

                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        Mail::to($recipient)->send($mailable);
                        return ['queued' => true, 'delay_seconds' => $delaySeconds, 'error' => null];
                    } catch (\Throwable $e) {
                        if ($attempt >= 3 || !$this->looksRateLimited($e->getMessage())) {
                            throw $e;
                        }

                        $retryDelay = $this->reserveDeliverySlot();
                        if ($retryDelay > 0) {
                            sleep($retryDelay);
                        }
                    }
                }
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

            return [
                'queued' => false,
                'delay_seconds' => $delaySeconds,
                'error' => $this->friendlyError($e->getMessage()),
            ];
        }
    }

    /**
     * Reserve a send slot at least three seconds after the previous slot.
     *
     * The timestamp is protected by an OS file lock, so concurrent PHP-FPM
     * requests and queue workers on the same CareVMA server share the same
     * throttle without needing a working database/cache connection.
     */
    private function reserveDeliverySlot(): int
    {
        $directory = storage_path('framework/cache');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'carevma-mail-provider-slot.lock';
        $handle = @fopen($path, 'c+');

        if (!$handle) {
            // Safe fallback: spacing is preferable to an unthrottled burst.
            return 3;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return 3;
            }

            rewind($handle);
            $stored = trim((string) stream_get_contents($handle));
            $nextAvailable = is_numeric($stored) ? (int) $stored : 0;
            $now = time();
            $sendAt = max($now, $nextAvailable);
            $delay = max(0, $sendAt - $now);
            $nextSlot = $sendAt + 3;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $nextSlot);
            fflush($handle);
            flock($handle, LOCK_UN);

            return min($delay, 180);
        } finally {
            fclose($handle);
        }
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

    private function friendlyError(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'authentication failed') || str_contains($lower, '535')) {
            return 'SMTP authentication failed. Verify MAIL_USERNAME and MAIL_PASSWORD, then clear Laravel config cache.';
        }

        if ($this->looksRateLimited($message)) {
            return 'The SMTP provider rate limit was reached. CareVMA throttled and retried the message, but the provider still rejected it.';
        }

        if (str_contains($lower, 'connection') || str_contains($lower, 'timed out') || str_contains($lower, 'could not connect')) {
            return 'CareVMA could not connect to the SMTP server. Verify MAIL_HOST, MAIL_PORT, encryption, and firewall settings.';
        }

        return 'SMTP delivery failed. Check the Laravel mail log and active mail configuration.';
    }
}
