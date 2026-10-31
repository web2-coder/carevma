<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HrInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $hrUser,
        public string $setupUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Set up your CareVMA HR account')
            ->view('emails.hr-invitation');
    }
}
