<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public string $activationUrl,
    ) {}

    public function build(): self
    {
        return $this->subject('[CareVMA] Set up your Team Member / VA account')
            ->view('emails.employee_invitation');
    }
}
