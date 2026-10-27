@extends('emails.layout')
@section('title', 'Set Your CareVMA Password')
@section('preheader', 'Your CareVMA Team Member / VA account is ready.')
@section('heading', 'Set Your Password')
@section('content')
<p style="margin:0 0 18px;">Hi {{ $employee->first_name }},</p>
<p style="margin:0 0 16px;">Your CareVMA Team Member / VA account has been created successfully.</p>
<p style="margin:0 0 20px;">Use the secure button below to choose your password and activate your account.</p>
@include('emails.partials.action-button', ['actionUrl' => $activationUrl, 'actionLabel' => 'Set Password'])
<p style="margin:0 0 18px;font-size:12px;color:#6e7882;"><strong>Security note:</strong> This invitation is private and may expire. If it expires, ask your CareVMA administrator to resend it.</p>
<p style="margin:0 0 6px;">After activation, sign in with <strong>{{ $employee->email }}</strong>.</p>
<p style="margin:18px 0 6px;">Thank you!</p><p style="margin:0 0 22px;font-weight:700;color:#38434d;">CareVMA Team</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f7;border-radius:5px;"><tr><td style="padding:16px 18px;text-align:center;font-size:11px;color:#7a838b;word-break:break-all;">If the button does not work, copy this link:<br><a href="{{ $activationUrl }}" style="color:#3c52a4;text-decoration:none;">{{ $activationUrl }}</a></td></tr></table>
@endsection
