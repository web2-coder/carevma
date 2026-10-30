@extends('emails.layout')
@section('title', 'Set Your CareVMA HR Password')
@section('preheader', 'Your CareVMA HR account is ready.')
@section('heading', 'Set Your HR Password')
@section('content')
<p style="margin:0 0 18px;">Hi {{ $hrUser->name }},</p>
<p style="margin:0 0 16px;">An administrator created a CareVMA HR account for you.</p>
<p style="margin:0 0 20px;">Use the secure link below to set your password and then sign in to the HR portal.</p>
@include('emails.partials.action-button', ['actionUrl' => $setupUrl, 'actionLabel' => 'Set Password & Sign In'])
<p style="margin:0 0 18px;font-size:12px;color:#6e7882;"><strong>Security note:</strong> This secure link may expire. If it is no longer valid, contact your CareVMA administrator for a new invitation.</p>
<p style="margin:18px 0 6px;">Thank you!</p><p style="margin:0 0 22px;font-weight:700;color:#38434d;">CareVMA Team</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f7;border-radius:5px;"><tr><td style="padding:16px 18px;text-align:center;font-size:11px;color:#7a838b;word-break:break-all;">If the button does not work, copy this link:<br><a href="{{ $setupUrl }}" style="color:#3c52a4;text-decoration:none;">{{ $setupUrl }}</a></td></tr></table>
@endsection
