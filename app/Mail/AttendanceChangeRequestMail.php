<?php

namespace App\Mail;

use App\Models\AttendanceChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AttendanceChangeRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AttendanceChangeRequest $changeRequest) {}

    public function build(): self
    {
        $this->changeRequest->loadMissing(['employee', 'attendance.client']);
        $timezone = $this->changeRequest->attendance?->client?->timezone
            ?: $this->changeRequest->attendance?->timezone_snapshot
            ?: 'UTC';
        $employee = trim(($this->changeRequest->employee?->first_name ?? '').' '.($this->changeRequest->employee?->last_name ?? ''));

        return $this->subject('[CareVMA] Attendance change request'.($employee ? ' - '.$employee : ''))
            ->view('emails.attendance_change_request')
            ->with(['clientTimezone' => $timezone]);
    }
}
