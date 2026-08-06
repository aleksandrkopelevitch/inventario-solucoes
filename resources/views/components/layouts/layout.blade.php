<!DOCTYPE html>
<html lang="pt-BR" class="overflow-x-clip">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Barlow/Barlow Condensed dropped 2026-07-28 — --font-sans/--font-display
         now use the system font stack (see app.css); only Space Mono is still
         a webfont. --}}
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-clip bg-surface text-body font-sans text-[14.5px] antialiased">

@php
    $sections = [
        'Catálogo' => [
            ['route' => 'profile.show', 'label' => 'Visão geral', 'icon' => 'home', 'active' => 'profile.show'],
            ['route' => 'solutions.index', 'label' => 'Soluções', 'icon' => 'squares-2x2', 'active' => ['solutions.index', 'solutions.show', 'solutions.integrations.*']],
            ['route' => 'people.index', 'label' => 'Pessoas', 'icon' => 'users', 'active' => 'people.*'],
            ['route' => 'companies.index', 'label' => 'Empresas', 'icon' => 'building-office-2', 'active' => 'companies.*'],
        ],
        'Governança' => [
            ['route' => 'documentation.index', 'label' => 'Documentação', 'icon' => 'book-open', 'active' => 'documentation.*'],
            ['route' => 'solutions.map', 'label' => 'Mapa do ecossistema', 'icon' => 'share', 'active' => 'solutions.map'],
            ['route' => 'flowspec.index', 'label' => 'Especialista em Integrações', 'icon' => 'cpu-chip', 'active' => 'flowspec.*'],
        ],
    ];
@endphp

<div class="grid min-h-screen md:grid-cols-[72px_1fr]">

    {{-- Sidebar — icon-only rail; each item projects its label as a flyout on
         hover (see the hover flyout <span> below). --}}
    <aside class="sticky top-0 z-40 flex h-screen flex-col items-center gap-1 bg-sidebar bg-linear-to-b from-sidebar-top to-sidebar-bottom px-3 py-4 text-sidebar-ink max-md:hidden">
        <a href="{{ route('profile.show') }}" class="mb-1.5 flex size-10 shrink-0 items-center justify-center no-underline" title="Leo Madeiras — Inventário">
            <span class="flex size-9 items-center justify-center rounded-field bg-white font-display text-base font-bold text-sidebar">L</span>
        </a>

        @foreach ($sections as $sectionLabel => $items)
            @unless ($loop->first)
                <div class="my-1 h-px w-7 bg-white/10"></div>
            @endunless
            <nav class="flex w-full flex-col items-center gap-1" aria-label="{{ $sectionLabel }}">
                @foreach ($items as $item)
                    @php
                        $has = \Illuminate\Support\Facades\Route::has($item['route']);
                        $on = $has && request()->routeIs(...(array) $item['active']);
                    @endphp
                    <a href="{{ $has ? route($item['route']) : '#' }}"
                       @class([
                           'group relative flex h-10 w-full items-center justify-center rounded-field transition-colors',
                           'text-sidebar-ink hover:bg-white/[0.06]' => ! $on,
                           'bg-lime/15' => $on,
                           'pointer-events-none opacity-40' => ! $has,
                       ])>
                        @if ($on)
                            {{-- Active indicator: the lime bar now carries a soft lime glow
                                 (the brand's one vivid color, finally in a lead role). --}}
                            <span class="absolute -left-3 inset-y-2 w-[3px] rounded-r bg-lime shadow-[0_0_10px_1px_rgba(170,219,30,0.55)]"></span>
                        @endif
                        <x-dynamic-component :component="'heroicon-o-'.$item['icon']" @class(['size-5 transition-colors', 'text-lime' => $on, 'text-sidebar-faint group-hover:text-white' => ! $on]) />

                        {{-- Hover flyout: label projects out to the right of the
                             icon, continuing the sidebar green, rounded on the
                             outer (right) corners. Purely visual (pointer-events-none). --}}
                        <span class="pointer-events-none absolute left-full top-0 z-50 flex h-10 translate-x-1 items-center whitespace-nowrap rounded-r-field bg-sidebar pl-3 pr-4 text-sm font-medium text-white opacity-0 shadow-[8px_0_20px_-6px_rgba(0,0,0,0.35)] transition-[opacity,transform] duration-150 group-hover:translate-x-0 group-hover:opacity-100">
                            {{ $item['label'] }}
                        </span>
                    </a>
                @endforeach
            </nav>
        @endforeach

        <div class="flex-1"></div>

        <x-layouts.user-menu />
    </aside>

    {{-- Main --}}
    {{-- `fluid` (opt-in per page, e.g. the flowSpec chat) pins the column to the
         viewport height so the page can build its own internal scroll + footer
         (composer) instead of the default document-scroll canvas. --}}
    <div @class(['relative flex min-w-0 flex-col', 'h-screen overflow-hidden' => ($fluid ?? false)])>
        {{-- `fluid` pages (the flowSpec chat) run edge-to-edge without the
             breadcrumb header — they own their whole viewport height. --}}
        @unless ($fluid ?? false)
            {{-- Ambient corner glow — the model's `.glow-corner`, anchored to
                 this FULL-WIDTH column (not `<main>`, which is capped at
                 max-w-[1080px] and centered) so it always bleeds from the
                 page's actual top-right corner, even on wide viewports where
                 `<main>` is narrower than the page (see
                 [[radiant-protocol-redesign]]/[[documentation-model-artifact]]).
                 `html`/`body{overflow-x-clip}` clip the negative `-right`
                 offset instead of `overflow-hidden` here (would break the
                 sticky header below, same ancestor) — `clip` unlike `hidden`
                 doesn't turn the root into a scroll container, so
                 `position: sticky` still resolves against the real viewport
                 (verified: `overflow-x-hidden` on html/body silently breaks
                 every sticky header in the app). `-z-10` on this
                 non-stacking-context `relative` parent is the same idiom
                 already used for the flowSpec badge glow. --}}
            <div class="pointer-events-none absolute -top-[220px] -right-[160px] -z-10 h-[520px] w-[640px] rounded-full opacity-50 blur-[70px]"
                 style="background: conic-gradient(from 140deg, var(--color-glow-a), var(--color-glow-b), var(--color-glow-c), var(--color-glow-a))"></div>
        <header class="sticky top-0 z-30 flex h-14 items-center justify-between gap-4 border-b border-line/60 bg-surface/70 px-5 shadow-sm backdrop-blur-md md:px-8">
            <div class="flex min-w-0 items-center gap-2 text-[13.5px] text-faint">
                <x-forms.button type="button" variant="ghost" data-ak-mobile-nav-open aria-label="Abrir menu"
                    class="md:hidden !size-9 !justify-center !rounded-field !p-0 !text-muted hover:!bg-raised hover:!text-ink">
                    <x-heroicon-o-bars-3 class="size-5" />
                </x-forms.button>
                <a href="{{ route('profile.show') }}" class="text-muted no-underline hover:text-ink">Inventário</a>
                @forelse ($breadcrumbs ?? [] as $crumb)
                    <x-heroicon-o-chevron-right class="size-4 shrink-0" />
                    @if ($crumb['url'] ?? null)
                        <a href="{{ $crumb['url'] }}" @class([
                            'truncate no-underline hover:text-ink',
                            'font-semibold text-ink hover:underline' => $loop->last,
                            'text-muted' => ! $loop->last,
                        ])>{{ $crumb['label'] }}</a>
                    @else
                        <b class="truncate font-semibold text-ink">{{ $crumb['label'] }}</b>
                    @endif
                @empty
                    <x-heroicon-o-chevron-right class="size-4 shrink-0" />
                    <b class="truncate font-semibold text-ink">{{ $title ?? 'Visão geral' }}</b>
                @endforelse
            </div>
            <div class="flex items-center gap-2">
                {{ $actions ?? '' }}
            </div>
        </header>
        @endunless

        <main @class([
            'mx-auto w-full max-w-[1080px] px-5 pb-24 pt-7 md:px-8' => ! ($fluid ?? false),
            'flex min-h-0 flex-1 flex-col' => ($fluid ?? false),
        ])>
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Alert Modal --}}
<dialog id="alert-modal" class="fixed inset-0 m-auto w-full max-w-sm rounded-card border border-line p-0 shadow-xl backdrop:bg-black/40">
    <div class="p-6">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 shrink-0">
                <span data-icon-success class="hidden text-lg text-lime-ink">✓</span>
                <span data-icon-warning class="hidden text-lg text-hot">⚠</span>
                <span data-icon-error class="hidden text-lg text-crit">✕</span>
                <span data-icon-info class="hidden text-lg text-accent">ℹ</span>
            </div>
            <div class="min-w-0 flex-1">
                <p data-title class="mb-1 text-sm font-semibold text-ink"></p>
                <p data-content class="text-sm text-muted"></p>
            </div>
        </div>
        <div class="mt-5 flex justify-end">
            <x-forms.button type="button" data-close>OK</x-forms.button>
        </div>
    </div>
</dialog>

{{-- Main Modal — content loaded via AJAX (Modal.loadFromURLAndOpen) --}}
<dialog id="main-modal" class="fixed inset-0 m-auto w-full max-w-2xl rounded-card border border-line p-0 shadow-2xl backdrop:bg-black/50">
    <div data-loading class="flex items-center justify-center p-12">
        <div class="flex gap-1.5">
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:0s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.15s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.3s"></span>
        </div>
    </div>
    <div data-content></div>
</dialog>

{{-- Toast Container --}}
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

{{-- Side Panel — generic shell, content loaded via AJAX --}}
<div id="side-panel-overlay"
     class="pointer-events-none fixed inset-0 z-40 bg-black/30 opacity-0 transition-opacity duration-300"
     data-ak-panel-close></div>

<aside id="side-panel"
       class="fixed right-0 top-0 z-50 flex h-full w-96 max-w-full translate-x-full flex-col bg-surface text-body shadow-2xl transition-transform duration-300">
    <div data-panel-placeholder class="flex flex-1 items-center justify-center">
        <div class="flex gap-1.5">
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:0s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.15s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.3s"></span>
        </div>
    </div>
</aside>

{{-- Mobile navigation drawer — the section nav (desktop icon rail is hidden
     below md) as a left slide-in panel with expanded icons + labels. Opened
     by any [data-ak-mobile-nav-open] trigger; closed by the overlay, the
     close button, each nav link, or Esc (see mobile-nav.js). --}}
<div id="mobile-nav-overlay"
     class="pointer-events-none fixed inset-0 z-[60] bg-black/40 opacity-0 transition-opacity duration-300 md:hidden"
     data-ak-mobile-nav-close></div>

<aside id="mobile-nav" aria-hidden="true"
       class="fixed left-0 top-0 z-[70] flex h-full w-72 max-w-[82%] -translate-x-full flex-col bg-sidebar bg-linear-to-b from-sidebar-top to-sidebar-bottom text-sidebar-ink shadow-2xl transition-transform duration-300 md:hidden">
    <div class="flex items-center justify-between px-4 py-4">
        <a href="{{ route('profile.show') }}" class="flex items-center gap-2 no-underline">
            <span class="flex size-9 items-center justify-center rounded-field bg-white font-display text-base font-bold text-sidebar">L</span>
            <span class="font-display text-sm font-semibold text-white">Inventário</span>
        </a>
        <x-forms.button type="button" variant="ghost" data-ak-mobile-nav-close aria-label="Fechar menu"
            class="!size-9 !justify-center !rounded-field !p-0 !text-sidebar-faint hover:!bg-white/[0.06] hover:!text-white">
            <x-heroicon-o-x-mark class="size-5" />
        </x-forms.button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 pb-4">
        @foreach ($sections as $sectionLabel => $items)
            <p class="px-3 pb-1.5 pt-4 text-[11px] font-semibold uppercase tracking-wide text-sidebar-faint">{{ $sectionLabel }}</p>
            @foreach ($items as $item)
                @php
                    $has = \Illuminate\Support\Facades\Route::has($item['route']);
                    $on = $has && request()->routeIs(...(array) $item['active']);
                @endphp
                <a href="{{ $has ? route($item['route']) : '#' }}"
                   data-ak-mobile-nav-close
                   @class([
                       'group relative flex items-center gap-3 rounded-field px-3 py-2.5 text-sm font-medium no-underline transition-colors',
                       'text-sidebar-ink hover:bg-white/[0.06] hover:text-white' => ! $on,
                       'bg-lime/15 text-white' => $on,
                       'pointer-events-none opacity-40' => ! $has,
                   ])>
                    @if ($on)
                        <span class="absolute left-0 inset-y-2 w-[3px] rounded-r bg-lime shadow-[0_0_10px_1px_rgba(170,219,30,0.55)]"></span>
                    @endif
                    <x-dynamic-component :component="'heroicon-o-'.$item['icon']" @class(['size-5 shrink-0 transition-colors', 'text-lime' => $on, 'text-sidebar-faint group-hover:text-white' => ! $on]) />
                    {{ $item['label'] }}
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="flex items-center gap-3 border-t border-white/10 px-4 py-3">
        <x-ui.avatar :name="auth()->user()->name" :src="auth()->user()->avatarUrl()" size="md" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</p>
        </div>
        <a href="#" data-ak-modal-open="main-modal" data-ak-modal-url="{{ route('profile.edit') }}"
           data-ak-mobile-nav-close aria-label="Editar perfil"
           class="flex size-9 shrink-0 items-center justify-center rounded-field text-sidebar-faint no-underline hover:bg-white/[0.06] hover:text-white">
            <x-heroicon-o-user-circle class="size-5" />
        </a>
    </div>
</aside>

</body>
</html>
