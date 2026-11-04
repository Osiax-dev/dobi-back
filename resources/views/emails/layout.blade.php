<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'DOBI')</title>
</head>
<body style="margin:0;padding:0;background:#f5f1e8;font-family:Arial,Helvetica,sans-serif;color:#102f2b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1e8;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
    <tr><td style="background:#0b332b;border-radius:14px 14px 0 0;padding:22px 28px;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="padding-right:12px;"><img src="{{ isset($message) ? $message->embed(public_path('images/dobi-logo.png')) : rtrim(config('app.url'), '/').'/images/dobi-logo.png' }}" width="40" height="40" alt="DOBI" style="display:block;border-radius:8px;border:0;"></td>
        <td><span style="font-size:24px;font-weight:bold;color:#ffffff;letter-spacing:1px;">DOBI</span><br><span style="font-size:12px;color:#d5f06a;">Les données ont une histoire.</span></td>
      </tr></table>
    </td></tr>
    <tr><td style="background:#ffffff;padding:28px;border-radius:0 0 14px 14px;font-size:15px;line-height:1.6;">
      @yield('content')
    </td></tr>
    <tr><td style="padding:16px 8px;text-align:center;font-size:12px;color:#5b6f6b;">
      Cet e-mail a été envoyé automatiquement par DOBI. Merci de ne pas y répondre.<br>
      &copy; {{ date('Y') }} DOBI
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
