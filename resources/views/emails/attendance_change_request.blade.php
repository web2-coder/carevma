@php use Carbon\Carbon; $clientTimezone = $clientTimezone ?? 'UTC'; $actionUrl = url('/admin/change-requests?request_id='.$changeRequest->id); @endphp
@extends('emails.layout')
@section('title', 'Attendance Change Request')
@section('preheader', 'A VA submitted an attendance correction request.')
@section('heading', 'Attendance Change Request')
@section('content')
<p style="margin:0 0 18px;">Hello CareVMA Operations Team,</p>
<p style="margin:0 0 18px;"><strong>{{ $changeRequest->employee->first_name }} {{ $changeRequest->employee->last_name }}</strong> submitted an attendance correction request.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e3e8ec;border-radius:6px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;width:42%;">Date</td><td style="padding:10px 12px;">{{ Carbon::parse($changeRequest->date)->format('d M Y') }} ({{ $clientTimezone }})</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Requested Clock In</td><td style="padding:10px 12px;">{{ $changeRequest->requested_clock_in ? Carbon::parse($changeRequest->requested_clock_in)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Requested Clock Out</td><td style="padding:10px 12px;">{{ $changeRequest->requested_clock_out ? Carbon::parse($changeRequest->requested_clock_out)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Reason</td><td style="padding:10px 12px;">{{ $changeRequest->reason ?: '-' }}</td></tr>
</table>
@include('emails.partials.action-button', ['actionUrl' => $actionUrl, 'actionLabel' => 'Review Request'])
@endsection
