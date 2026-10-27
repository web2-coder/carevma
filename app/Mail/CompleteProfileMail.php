<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompleteProfileMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $employee, public string $temporaryUrl) {}

    public function build(): self
    {
        return $this->subject('[CareVMA] Complete your profile')
            ->view('emails.complete_profile');
    }
}
