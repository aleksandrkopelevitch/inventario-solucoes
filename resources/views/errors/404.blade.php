{{-- Custom 404. Deliberately self-contained (its own <html>, not
     `x-layouts.layout`): the app shell renders a sidebar with the signed-in
     user's avatar, and this page also serves the ONE unauthenticated surface
     in the app — an expired, revoked or mistyped public documentation
     magic-link (`PublicDocumentationController::resolve()`'s `firstOrFail()`).
     Those visitors are partners/vendors with no Leo Madeiras login, and they
     used to land on Laravel's generic unbranded 404 with no explanation of
     what went wrong or how to get a working link.

     Reached via `bootstrap/app.php`'s NotFoundHttpException renderer, which
     only calls `abort(404)` when `app.debug` is off — locally you still get
     the debug page. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Página não encontrada · Inventário de Soluções</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-body font-sans antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-xl flex-col items-center justify-center px-6 py-12 text-center">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-lg font-bold text-white">L</span>

        <p class="mt-6 font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">Erro 404</p>
        <h1 class="mt-2 font-display text-2xl font-semibold text-ink">Página não encontrada</h1>

        <p class="mt-4 text-sm leading-relaxed text-muted">
            O endereço não existe ou o conteúdo foi movido. Se você chegou aqui
            por um link de documentação compartilhado, ele pode ter expirado ou
            sido revogado — peça um novo link a quem o enviou.
        </p>

        @auth
            <a href="{{ url('/') }}"
               class="mt-7 inline-flex items-center gap-2 rounded-field border border-line bg-surface px-4 py-2 text-sm font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                Voltar ao início
            </a>
        @endauth
    </div>
</body>
</html>
