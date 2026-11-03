<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirme seu novo e-mail</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .header { background: #0a0a0a; padding: 32px; text-align: center; }
        .header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; letter-spacing: -0.3px; }
        .body { padding: 36px 40px; }
        .body p { margin: 0 0 16px; color: #374151; font-size: 15px; line-height: 1.6; }
        .body p.greeting { font-size: 16px; font-weight: 600; color: #111827; }
        .cta { display: block; margin: 28px 0 0; text-align: center; }
        .cta a { display: inline-block; background: #0a0a0a; color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 12px 28px; border-radius: 8px; }
        .footer { padding: 20px 40px; border-top: 1px solid #f3f4f6; text-align: center; }
        .footer p { margin: 0; color: #9ca3af; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>{{ config('app.name') }}</h1>
        </div>

        <div class="body">
            <p class="greeting">Olá, {{ $user->name }}!</p>
            <p>Pediram para trocar o e-mail da sua conta no {{ config('app.name') }} para <strong>{{ $newEmail }}</strong>.</p>
            <p>O e-mail só muda depois que você confirmar por aqui. Se não foi você, ignore esta mensagem — nada muda.</p>

            <div class="cta">
                <a href="{{ $confirmUrl }}">Confirmar novo e-mail</a>
            </div>

            <p style="margin-top: 24px; font-size: 13px; color: #6b7280;">O link vale por 24 horas.</p>
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
