@if(!empty($actionUrl))
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;">
    <tr>
        <td bgcolor="#39c8df" style="border-radius:4px;">
            <a href="{{ $actionUrl }}" style="display:inline-block;padding:13px 22px;color:#08314d;text-decoration:none;font-size:13px;font-weight:700;">{{ $actionLabel ?? 'Open CareVMA' }}</a>
        </td>
    </tr>
</table>
@endif
