@extends('emails.layout')
@section('title', 'Complete Your CareVMA Profile')
@section('preheader', 'Complete the required profile information for your CareVMA account.')
@section('heading', 'Complete Your Profile')
@section('content')
<p style="margin:0 0 18px;">Hi {{ $employee->first_name }},</p>
<p style="margin:0 0 16px;">Your CareVMA account is ready for profile completion.</p>
<p style="margin:0 0 20px;">Use the button below to securely complete the required profile fields and unlock your workspace.</p>
@include('emails.partials.action-button', ['actionUrl' => $temporaryUrl, 'actionLabel' => 'Complete Profile'])
<p style="margin:0 0 18px;font-size:12px;color:#6e7882;"><strong>Security note:</strong> This link is time-limited. If it expires, request a new profile-completion link.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f5f6f7;border-radius:5px;"><tr><td style="padding:16px 18px;text-align:center;font-size:11px;color:#7a838b;word-break:break-all;">Backup link:<br><a href="{{ $temporaryUrl }}" style="color:#3c52a4;text-decoration:none;">{{ $temporaryUrl }}</a></td></tr></table>
@endsection
