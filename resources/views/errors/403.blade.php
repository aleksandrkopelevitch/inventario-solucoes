{{-- Custom 403, same self-contained shape as `errors/404.blade.php` (its own
     <html>, no app shell) — see that file for why. Reached by Laravel's own
     `renderHttpException()`, which picks up this view by status code: the
     403 renderer in `bootstrap/app.php` deliberately only answers the JSON
     case and lets HTML fall through to here.

     The audience is a signed-in viewer who reached an admin-only action, so
     unlike the 404 this always offers the way back. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sem permissão · Inventário de Soluções</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-body font-sans antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-xl flex-col items-center justify-center px-6 py-12 text-center">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-lg font-bold text-white">L</span>

        <p class="mt-6 font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">Erro 403</p>
        <h1 class="mt-2 font-display text-2xl font-semibold text-ink">Sem permissão</h1>

        <p class="mt-4 text-sm leading-relaxed text-muted">
            Você não tem permissão para acessar esta página. Editar o inventário
            é restrito a administradores — se precisar desse acesso, fale com
            quem administra o Inventário de Soluções.
        </p>

        <a href="{{ url('/') }}"
           class="mt-7 inline-flex items-center gap-2 rounded-field border border-line bg-surface px-4 py-2 text-sm font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
            Voltar ao início
        </a>
    </div>
</body>
</html>
