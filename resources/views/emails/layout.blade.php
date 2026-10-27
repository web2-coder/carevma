<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <title>@yield('title', 'CareVMA Notification')</title>
</head>
<body style="margin:0;padding:0;background:#f3f6f8;font-family:Arial,Helvetica,sans-serif;color:#27313a;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">@yield('preheader')</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f6f8;margin:0;padding:0;">
    <tr>
        <td align="center" style="padding:34px 14px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#ffffff;border:1px solid #e5e9ee;border-radius:12px;overflow:hidden;box-shadow:0 8px 28px rgba(16,38,60,.08);">
                <tr>
                    <td align="center" style="padding:28px 28px 18px;background:#ffffff;">
                        <img src="{{ $message->embed(public_path('images/CareVMA-email-logo.png')) }}" alt="CareVMA" width="190" style="display:block;width:190px;max-width:72%;height:auto;border:0;outline:none;text-decoration:none;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 34px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#173b8f;border-radius:5px;">
                            <tr>
                                <td style="padding:18px 22px;color:#ffffff;font-size:21px;line-height:1.25;font-weight:700;">
                                    @yield('heading', 'CareVMA Notification')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 34px 32px;font-size:14px;line-height:1.65;color:#4b5560;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 30px;background:#f5f7f8;border-top:1px solid #e8edf0;text-align:center;font-size:11px;line-height:1.55;color:#8a949d;">
                        This is an automated CareVMA notification. Please do not share secure account links.<br>
                        <span style="color:#66737d;">CareVMA Workforce Operations</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
