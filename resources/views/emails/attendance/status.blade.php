@php use Carbon\Carbon; $isApproved = strtolower($status) === 'approved'; $clientTimezone = $clientTimezone ?? 'UTC'; $attendanceId = $changeRequest->attendance_id; $actionUrl = url('/employee/attendance?attendance_id='.$attendanceId.'&request_id='.$changeRequest->id); @endphp
@extends('emails.layout')
@section('title', 'Attendance Request Update')
@section('preheader', 'Your attendance correction request has been reviewed.')
@section('heading', 'Attendance Request Update')
@section('content')
<p style="margin:0 0 18px;">Hi {{ $changeRequest->employee->first_name }},</p>
<p style="margin:0 0 18px;">Your attendance change request for <strong>{{ Carbon::parse($changeRequest->date)->format('d M Y') }}</strong> has been <strong style="color:{{ $isApproved ? '#16804b' : '#b42318' }};">{{ ucfirst($status) }}</strong>.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e3e8ec;border-radius:6px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;width:42%;">Old Clock In</td><td style="padding:10px 12px;">{{ $changeRequest->old_clock_in ? Carbon::parse($changeRequest->old_clock_in)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Old Clock Out</td><td style="padding:10px 12px;">{{ $changeRequest->old_clock_out ? Carbon::parse($changeRequest->old_clock_out)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Requested Clock In</td><td style="padding:10px 12px;">{{ $changeRequest->requested_clock_in ? Carbon::parse($changeRequest->requested_clock_in)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Requested Clock Out</td><td style="padding:10px 12px;">{{ $changeRequest->requested_clock_out ? Carbon::parse($changeRequest->requested_clock_out)->format('h:i A') : '-' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Timezone</td><td style="padding:10px 12px;">{{ $clientTimezone }}</td></tr>
</table>
@include('emails.partials.action-button', ['actionUrl' => $actionUrl, 'actionLabel' => 'View My Attendance'])
@endsection
