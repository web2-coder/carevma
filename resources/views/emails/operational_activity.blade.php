@extends('emails.layout')
@section('title', $title)
@section('preheader', $messageText)
@section('heading', $title)
@section('content')
<p style="margin:0 0 18px;">Hi {{ $recipientName ?: 'there' }},</p>
<p style="margin:0 0 20px;white-space:pre-line;">{{ $messageText }}</p>
@include('emails.partials.action-button', ['actionUrl' => $resolvedActionUrl ?? null, 'actionLabel' => $actionLabel ?? 'Open CareVMA'])
<p style="margin:0;font-size:12px;color:#7a838b;">Notification type: {{ $type }}</p>
@endsection
