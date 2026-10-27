<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function build(): self
    {
        $this->invoice->loadMissing('client');

        return $this->subject('[CareVMA] Invoice '.$this->invoice->invoice_number)
            ->view('emails.invoice_notification')
            ->with([
                'invoiceUrl' => url('/admin/invoices/'.$this->invoice->id),
                'invoicePdfUrl' => route('invoices.pdf', $this->invoice),
            ]);
    }
}
