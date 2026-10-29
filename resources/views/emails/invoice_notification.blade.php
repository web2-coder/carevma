@extends('emails.layout')
@section('title', 'CareVMA Invoice')
@section('preheader', 'Your CareVMA invoice is attached as a PDF.')
@section('heading', 'Invoice Ready')
@section('content')
<p style="margin:0 0 18px;">Hello {{ $invoice->client?->name ?? 'Doctor' }},</p>
<p style="margin:0 0 18px;">Your CareVMA invoice <strong>{{ $invoice->invoice_number }}</strong> is attached to this email as a PDF.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e3e8ec;border-radius:6px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;width:40%;">Invoice</td><td style="padding:10px 12px;">{{ $invoice->invoice_number }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Billing period</td><td style="padding:10px 12px;">{{ optional($invoice->period_start)->format('d M Y') ?? $invoice->period_start }} – {{ optional($invoice->period_end)->format('d M Y') ?? $invoice->period_end }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Grand total</td><td style="padding:10px 12px;"><strong>${{ number_format((float) $invoice->total_amount, 2) }}</strong></td></tr>
</table>
<p style="margin:0;font-size:12px;color:#6e7882;">Please see the attached PDF for the complete invoice, service details and payment information.</p>
@endsection
