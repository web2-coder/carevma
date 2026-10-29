<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $pdfContent) {}

    public function build(): self
    {
        $this->invoice->loadMissing('client');

        return $this->subject('[CareVMA] Invoice '.$this->invoice->invoice_number)
            ->view('emails.invoice_notification')
            ->attachData(
                $this->pdfContent,
                'CareVMA-Invoice-'.$this->invoice->invoice_number.'.pdf',
                ['mime' => 'application/pdf']
            );
    }
}
