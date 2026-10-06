{{-- Shell for a public (magic-link) screen that is not a caderno — today the
     solutions spreadsheet. The `public-docs` shell is built around a reading
     column, a page rail and a search palette; this one is a top bar and a
     full-height body, because a spreadsheet needs the whole viewport. --}}
@props(['title' => null])
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title . ' · Leo Madeiras' : 'Leo Madeiras' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-dvh flex-col bg-canvas font-sans text-[14.5px] text-body antialiased">

    <header class="flex shrink-0 items-center gap-3 border-b border-line bg-white px-4 py-3 sm:px-5">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-sm font-bold text-white">L</span>
        <p class="truncate font-display text-base font-semibold leading-tight text-ink">Inventário de Soluções · Leo Madeiras</p>
        <span class="ml-auto shrink-0 rounded-full border border-line bg-canvas px-2 py-0.5 text-[11px] font-medium text-muted">Somente leitura</span>
    </header>

    <div class="flex min-h-0 flex-1 flex-col">
        {{ $slot }}
    </div>

    {{-- Toast — same shell as the main layout. --}}
    <div id="toast-container" class="fixed right-4 top-4 z-50 flex w-80 flex-col gap-2">
        <div id="toast-template" class="hidden rounded-card border border-line bg-surface p-4 opacity-0 shadow-lg transition-all duration-200">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 shrink-0">
                    <span data-icon-success class="hidden text-base text-lime-ink">✓</span>
                    <span data-icon-warning class="hidden text-base text-hot">⚠</span>
                    <span data-icon-error class="hidden text-base text-crit">✕</span>
                    <span data-icon-info class="hidden text-base text-accent">ℹ</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p data-slot="title" class="text-sm font-semibold text-ink"></p>
                    <p data-slot="content" class="mt-0.5 text-sm text-muted"></p>
                </div>
                <x-forms.button type="button" variant="ghost" class="!rounded-none !p-0 !text-lg !leading-none !font-normal shrink-0 !text-faint hover:!bg-transparent hover:!text-body">×</x-forms.button>
            </div>
            <div class="mt-3 h-0.5 overflow-hidden rounded-full bg-raised">
                <div data-timer class="h-full rounded-full bg-accent" style="width:100%"></div>
            </div>
        </div>
    </div>
</body>
</html>
