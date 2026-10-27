@extends('emails.layout')
@section('title', 'Employee Probation Ending Soon')
@section('preheader', 'An employee probation period is approaching its end date.')
@section('heading', 'Probation Ending Soon')
@section('content')
@php $actionUrl = url('/admin/employees?employee_id='.$employee->id); @endphp
<p style="margin:0 0 18px;">Hello CareVMA Operations Team,</p>
<p style="margin:0 0 18px;">The probation period for the employee below is approaching its end date.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e3e8ec;border-radius:6px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;width:38%;">Employee</td><td style="padding:10px 12px;">{{ $employee->first_name }} {{ $employee->last_name }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Email</td><td style="padding:10px 12px;">{{ $employee->email }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Joining Date</td><td style="padding:10px 12px;">{{ $employee->date_of_joining ?: 'Not set' }}</td></tr>
<tr><td style="padding:10px 12px;background:#f6f8f9;font-weight:700;">Probation Ends</td><td style="padding:10px 12px;">{{ optional($employee->probationEndsOn())->format('d M Y') ?? 'Not set' }}</td></tr>
</table>
@include('emails.partials.action-button', ['actionUrl' => $actionUrl, 'actionLabel' => 'Review Employee'])
@endsection
