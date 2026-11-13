<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chamado #{{ $numero }} — {{ config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F5F7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F5F7; padding:32px 16px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FFFFFF; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">

<tr>
<td style="background-color:#FF8C1A; padding:28px 32px;">
<span style="font-size:20px; font-weight:700; color:#FFFFFF; letter-spacing:0.2px;">{{ config('app.name') }}</span>
</td>
</tr>

<tr>
<td style="padding:32px;">
<p style="margin:0 0 8px; font-size:14px; color:#7922B9; font-weight:600; text-transform:uppercase; letter-spacing:0.4px;">
{{ $reabertura ? 'Chamado reaberto pelo cliente' : 'Novo comentário do cliente' }}
</p>
<h1 style="margin:0 0 20px; font-size:22px; color:#1A1A1A; font-weight:700;">Chamado #{{ $numero }} — {{ $titulo }}</h1>
<p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#3C3C43;">
Olá, {{ $agente }}. <strong>{{ $cliente }}</strong>{{ $empresa ? " ({$empresa})" : '' }} {{ $reabertura ? 'reabriu este chamado pelo portal:' : 'comentou neste chamado pelo portal:' }}
</p>

<div style="margin:0 0 24px; padding:16px 20px; background-color:#F5F5F7; border-left:4px solid #FF8C1A; border-radius:6px; font-size:15px; line-height:1.6; color:#1A1A1A; white-space:pre-wrap;">{{ $comentario }}</div>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
<tr>
<td style="border-radius:8px; background-color:#FF8C1A;">
<a href="{{ $url }}" target="_blank" style="display:inline-block; padding:14px 28px; font-size:15px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:8px;">
Abrir o chamado
</a>
</td>
</tr>
</table>

<p style="margin:0; font-size:12px; line-height:1.6; color:#9A9AA0; word-break:break-all;">
Se o botão não funcionar, copie e cole este link no navegador:<br>
<a href="{{ $url }}" style="color:#7922B9;">{{ $url }}</a>
</p>
</td>
</tr>

<tr>
<td style="padding:20px 32px; background-color:#F5F5F7; border-top:1px solid #ECECEE;">
<p style="margin:0; font-size:12px; color:#9A9AA0;">{{ config('app.name') }} &middot; e-mail automático, não responda — responda pelo chamado.</p>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
