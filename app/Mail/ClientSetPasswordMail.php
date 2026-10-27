<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientSetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $client, public string $url) {}

    public function build(): self
    {
        return $this->subject('[CareVMA] Set up your Client / Doctor account')
            ->view('emails.client-set-password');
    }
}
