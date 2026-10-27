<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProbationEndingSoonMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $employee) {}

    public function build(): self
    {
        return $this->subject('[CareVMA] Probation ending soon - '.$this->employee->first_name)
            ->view('emails.probation_ending');
    }
}
