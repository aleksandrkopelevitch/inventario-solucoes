@props([
    'title' => null,
    'heading' => '',
    'nav' => null,
    'searchUrl' => null,
    'searchResults' => null,
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

    {{-- Top bar: Leo brand + caderno name (stays white over the cream canvas) --}}
    <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-white px-4 py-3 sm:px-6">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-sm font-bold text-white">L</span>
        <div class="min-w-0">
            <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-accent">Documentação</p>
            <p class="truncate font-display text-base font-semibold leading-tight text-ink">{{ $heading }}</p>
        </div>

        {{-- The search trigger, and nothing else: a field-shaped button that
             opens the palette. The facet rows that used to sit under it moved
             INTO the palette, where they are visible exactly while a query is
             being typed. --}}
        @if ($searchUrl)
            <button type="button" data-ak-docs-search-open
                    class="ml-auto flex min-w-0 max-w-md flex-1 cursor-pointer items-center gap-2 rounded-field border border-line bg-canvas px-3 py-1.5 text-left text-sm text-faint transition-colors hover:border-accent-line hover:bg-surface">
                <x-heroicon-o-magnifying-glass class="size-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate">Buscar na documentação…</span>
                <span class="hidden shrink-0 rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[10px] sm:block">⌘K</span>
            </button>
        @endif
    </header>

    {{-- Three-pane docs shell (GitBook/Substack/Medium): pages rail pinned to
         the left, a centered reading column, and an "on this page" headings
         navigator on the right. Full-bleed so the pages rail sits flush left.

         It no longer hides itself during a search: the palette is a modal over
         the top layer, so the shell being visible underneath is the point
         rather than a conflict. The `data-ak-docs-search-active` marker the
         server still emits is what the palette reads to know it is narrowing;
         nothing toggles the shell any more. --}}
    <div data-ak-docs-shell class="flex w-full items-start gap-6 px-4 md:gap-8 md:px-6 lg:px-8">

        {{-- Pages index: this caderno's tree, collapsed to the path being read.

             Flat rows with `data-parent-id`, not nested lists — see
             docs-tree.js. The server ships the open/closed state
             (DocumentationPageService::navRows()), so this is right before any
             JavaScript runs; the module only handles clicks. --}}
        @if ($nav)
            <aside class="ak-sidebar ak-sidebar-scroll hidden w-64 shrink-0 md:sticky md:top-14 md:block md:max-h-[calc(100vh_-_3.5rem)] md:overflow-y-auto md:py-10">
                <p class="px-2 pb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted">Neste caderno</p>
                <nav data-ak-docs-tree class="flex flex-col gap-px">
                    @foreach ($nav as $item)
                        @php ($depth = (int) ($item['depth'] ?? 0))
                        {{-- One indent step per level, each hanging off a guide
                             line. Steps are listed rather than computed:
                             Tailwind only ships classes it can SEE in the
                             source, so `ml-{{ $n }}` compiles to nothing. --}}
                        <div data-ak-docs-tree-item
                             data-page-id="{{ $item['id'] }}"
                             data-parent-id="{{ $item['parentId'] ?? '' }}"
                             data-expanded="{{ ($item['expanded'] ?? false) ? 'true' : 'false' }}"
                             @class([
                                 'flex items-center gap-0.5',
                                 'hidden' => ! ($item['visible'] ?? true),
                                 'ml-3 border-l border-line pl-1' => $depth === 1,
                                 'ml-5 border-l border-line pl-1' => $depth === 2,
                                 'ml-7 border-l border-line pl-1' => $depth === 3,
                                 'ml-9 border-l border-line pl-1' => $depth >= 4,
                             ])>
                            <a href="{{ $item['url'] }}"
                               @class([
                                   'min-w-0 flex-1 truncate rounded-field px-2.5 py-1.5 text-[13.5px] no-underline transition-colors',
                                   'bg-accent-soft font-semibold text-accent' => $item['active'],
                                   'text-body hover:bg-raised hover:text-ink' => ! $item['active'],
                               ])>{{ $item['label'] }}</a>

                            {{-- The chevron lives OUTSIDE the link, so opening a
                                 branch never competes with opening the page —
                                 the two gestures that a single clickable row
                                 would have had to guess between. --}}
                            @if ($item['hasChildren'] ?? false)
                                <button type="button" data-ak-docs-tree-toggle
                                        aria-expanded="{{ ($item['expanded'] ?? false) ? 'true' : 'false' }}"
                                        class="group shrink-0 cursor-pointer rounded p-1 text-faint transition-colors hover:bg-raised hover:text-ink"
                                        aria-label="Mostrar ou ocultar sub-páginas de {{ $item['label'] }}">
                                    {{-- Rotation keys off the button's OWN
                                         `aria-expanded` via a first-class
                                         Tailwind variant. An arbitrary variant
                                         (`[[data-expanded=true]_&]`) reads the
                                         row instead and is a silent no-op the
                                         day its value grows an underscore —
                                         Tailwind turns `_` into a space inside
                                         arbitrary values. --}}
                                    <x-heroicon-o-chevron-right class="size-3.5 transition-transform duration-150 group-aria-expanded:rotate-90" />
                                </button>
                            @endif
                        </div>
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

    {{-- The search palette. Mounted at the end of the body, outside the
         reading shell: a <dialog> in the top layer is not affected by any
         ancestor's stacking or overflow, which is what the inline panel had to
         fight when it sat inside the sticky header's flow. --}}
    @if ($searchUrl)
        <x-documentation.search-panel :url="$searchUrl" :results="$searchResults" />
    @endif

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
