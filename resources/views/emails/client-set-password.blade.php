@extends('emails.layout')
@section('title', 'Set Your CareVMA Client Password')
@section('preheader', 'Your CareVMA client account is ready.')
@section('heading', 'Set Your Client Password')
@section('content')
<p style="margin:0 0 18px;">Hi {{ $client->name }},</p>
<p style="margin:0 0 16px;">Your CareVMA Client / Doctor account has been created successfully.</p>
<p style="margin:0 0 20px;">Set your password to activate your account and access your assigned VAs, schedules, attendance and reports.</p>
@include('emails.partials.action-button', ['actionUrl' => $url, 'actionLabel' => 'Set Password'])
<p style="margin:0 0 18px;font-size:12px;color:#6e7882;"><strong>Security note:</strong> This secure link may expire. If it is no longer valid, contact your CareVMA administrator for a new invitation.</p>
<p style="margin:18px 0 6px;">Thank you!</p><p style="margin:0 0 22px;font-weight:700;color:#38434d;">CareVMA Team</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f7;border-radius:5px;"><tr><td style="padding:16px 18px;text-align:center;font-size:11px;color:#7a838b;word-break:break-all;">If the button does not work, copy this link:<br><a href="{{ $url }}" style="color:#3c52a4;text-decoration:none;">{{ $url }}</a></td></tr></table>
@endsection
