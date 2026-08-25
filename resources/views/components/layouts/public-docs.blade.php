@props([
    'title' => null,
    'heading' => '',
    'nav' => null,
])
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title . ' · Documentação' : 'Documentação' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Barlow/Barlow Condensed dropped 2026-07-28 — --font-sans/--font-display
         now use the system font stack (see app.css); only Space Mono is still
         a webfont. --}}
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ak-docs-scroll min-h-screen bg-white text-body font-sans text-[14.5px] antialiased">

    {{-- Top bar: Leo brand + solution name (stays white over the cream canvas) --}}
    <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-white px-4 py-3 sm:px-6">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-sm font-bold text-white">L</span>
        <div class="min-w-0">
            <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-accent">Documentação</p>
            <p class="truncate font-display text-base font-semibold leading-tight text-ink">{{ $heading }}</p>
        </div>
    </header>

    {{-- Three-pane docs shell (GitBook/Substack/Medium): pages rail pinned to
         the left, a centered reading column, and an "on this page" headings
         navigator on the right. Full-bleed so the pages rail sits flush left. --}}
    <div class="flex w-full items-start gap-6 px-4 md:gap-8 md:px-6 lg:px-8">

        {{-- Pages index: all documentation pages for this solution --}}
        @if ($nav)
            <aside class="hidden w-60 shrink-0 md:sticky md:top-14 md:block md:max-h-[calc(100vh_-_3.5rem)] md:overflow-y-auto md:py-10">
                <p class="px-2 pb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted">Nesta solução</p>
                <nav class="flex flex-col gap-0.5">
                    {{-- A page's subpages are indented under it (the tree is
                         two levels deep): a visitor reading top to bottom
                         should see which pages belong to which. --}}
                    @foreach ($nav as $item)
                        <a href="{{ $item['url'] }}"
                           @class([
                               'flex items-center justify-between gap-2 rounded-field py-2 text-sm no-underline transition-colors',
                               'px-3' => ($item['depth'] ?? 0) === 0,
                               'ml-3 border-l border-line px-2.5 text-[13px]' => ($item['depth'] ?? 0) > 0,
                               'bg-accent-soft font-semibold text-accent' => $item['active'],
                               'text-body hover:bg-raised' => ! $item['active'],
                           ])>
                            <span class="truncate">{{ $item['label'] }}</span>
                            @unless ($item['hasDocs'])
                                <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-faint">vazio</span>
                            @endunless
                        </a>
                    @endforeach
                </nav>
            </aside>
        @endif

        {{-- Content — centered reading measure (GitBook/Medium/Substack). --}}
        <main class="min-w-0 flex-1 py-8 md:py-10">
            <div class="mx-auto w-full max-w-3xl">
                {{ $slot }}
            </div>
        </main>

        {{-- "Nesta página" headings navigator (H1/H2), built by docs-toc.js.
             Collapses itself when the content has no headings. --}}
        <aside data-ak-docs-toc
               class="hidden w-56 shrink-0 lg:sticky lg:top-14 lg:block lg:max-h-[calc(100vh_-_3.5rem)] lg:overflow-y-auto lg:py-10"></aside>
    </div>

    {{-- Toast — same shell as the main layout (Toast.show for "Copiar Markdown"). --}}
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
