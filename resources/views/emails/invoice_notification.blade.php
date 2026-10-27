@extends('emails.layout')
@section('title', 'CareVMA Invoice')
@section('preheader', 'A CareVMA invoice is ready to review.')
@section('heading', 'Invoice Ready')
@section('content')
<p style="margin:0 0 18px;">Hello CareVMA Operations Team,</p>
<p style="margin:0 0 18px;">Invoice <strong>{{ $invoice->invoice_number }}</strong> for <strong>{{ $invoice->client?->name ?? 'the selected client' }}</strong> is ready.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e3e8ec;border-radius:6px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;width:40%;">Invoice</td><td style="padding:10px 12px;">{{ $invoice->invoice_number }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Client</td><td style="padding:10px 12px;">{{ $invoice->client?->name ?? '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Period</td><td style="padding:10px 12px;">{{ optional($invoice->period_start)->format('d M Y') ?? $invoice->period_start }} – {{ optional($invoice->period_end)->format('d M Y') ?? $invoice->period_end }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Total</td><td style="padding:10px 12px;">${{ number_format((float) $invoice->total_amount, 2) }}</td></tr>
</table>
@include('emails.partials.action-button', ['actionUrl' => $invoiceUrl, 'actionLabel' => 'View Invoice'])
<p style="margin:0;font-size:12px;color:#6e7882;">PDF: <a href="{{ $invoicePdfUrl }}" style="color:#3c52a4;text-decoration:none;">Open invoice PDF</a></p>
@endsection
