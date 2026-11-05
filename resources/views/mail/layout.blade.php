<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ config('app.name') }}</title></head>
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:Arial,Helvetica,sans-serif;color:#1d2330;line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #d9dde5;border-radius:8px;">
<tr><td style="padding:24px;">
<p style="font-weight:bold;margin:0 0 16px;">{{ config('app.name') }}</p>
@yield('body')
<p style="color:#5b6475;font-size:13px;margin-top:24px;">This message was sent by the HR team. It contains no personal documents. If you were not expecting it, please ignore it or contact HR.</p>
</td></tr></table>
</td></tr></table>
</body>
</html>
