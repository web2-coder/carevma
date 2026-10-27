<?php

namespace App\Mail;

use App\Models\AttendanceChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AttendanceStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AttendanceChangeRequest $changeRequest,
        public string $status,
    ) {}

    public function build(): self
    {
        $this->changeRequest->loadMissing(['employee', 'attendance.client']);
        $timezone = $this->changeRequest->attendance?->client?->timezone
            ?: $this->changeRequest->attendance?->timezone_snapshot
            ?: 'UTC';
        return $this->subject('[CareVMA] Attendance request '.ucfirst($this->status))
            ->view('emails.attendance.status')
            ->with(['clientTimezone' => $timezone]);
    }
}
