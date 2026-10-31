<?php

namespace App\Mail;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class InvoiceNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $pdfContent) {}

    public function build(): self
    {
        $this->invoice->loadMissing('client');
        $doctor = trim((string) ($this->invoice->client?->name ?: $this->invoice->client?->business_name ?: 'Doctor'));
        $doctorFile = Str::of($doctor)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->value() ?: 'Doctor';
        $month = Carbon::parse($this->invoice->period_end)->format('M_Y');
        $fileName = $doctorFile.'_Invoice_'.$month.'.pdf';

        return $this->subject('[CareVMA] '.$doctor.' Invoice '.$this->invoice->invoice_number)
            ->view('emails.invoice_notification')
            ->attachData(
                $this->pdfContent,
                $fileName,
                ['mime' => 'application/pdf']
            );
    }
}
