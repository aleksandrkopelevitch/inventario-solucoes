<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-body font-sans text-[14.5px] antialiased">

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
            ['route' => 'flowspec.index', 'label' => 'Gerador de flowSpec', 'icon' => 'cpu-chip', 'active' => 'flowspec.*'],
        ],
    ];
@endphp

<div class="grid min-h-screen md:grid-cols-[244px_1fr]">

    {{-- Sidebar --}}
    <aside class="sticky top-0 z-40 flex h-screen flex-col gap-1.5 bg-sidebar px-3.5 py-4 text-sidebar-ink max-md:hidden">
        <a href="{{ route('profile.show') }}" class="flex items-center gap-3 px-2 pb-3.5 pt-1.5 no-underline">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-field bg-white font-display text-base font-bold text-sidebar">L</span>
            <span class="font-display text-lg font-semibold leading-none text-white">
                Leo Madeiras
                <span class="mt-0.5 block font-sans text-[10.5px] font-medium uppercase tracking-[0.14em] text-sidebar-faint">Inventário</span>
            </span>
        </a>

        @foreach ($sections as $sectionLabel => $items)
            <div class="px-2.5 pb-1.5 pt-3.5 text-[10px] font-bold uppercase tracking-[0.12em] text-sidebar-faint">{{ $sectionLabel }}</div>
            <nav class="flex flex-col gap-0.5">
                @foreach ($items as $item)
                    @php
                        $has = \Illuminate\Support\Facades\Route::has($item['route']);
                        $on = $has && request()->routeIs(...(array) $item['active']);
                    @endphp
                    <a href="{{ $has ? route($item['route']) : '#' }}"
                       @class([
                           'relative flex h-10 items-center gap-3 rounded-field px-3 text-sm font-medium transition-colors',
                           'text-sidebar-ink hover:bg-white/[0.06] hover:text-white' => ! $on,
                           'bg-white/10 font-semibold text-white' => $on,
                           'pointer-events-none opacity-40' => ! $has,
                       ])>
                        @if ($on)
                            <span class="absolute -left-3.5 inset-y-2 w-[3px] rounded-r bg-lime"></span>
                        @endif
                        <x-dynamic-component :component="'heroicon-o-'.$item['icon']" @class(['size-[18px]', 'text-lime' => $on, 'text-sidebar-faint' => ! $on]) />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        @endforeach

        <div class="flex-1"></div>

        <x-layouts.user-menu />
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-col">
        <header class="sticky top-0 z-30 flex h-14 items-center justify-between gap-4 border-b border-line bg-canvas/[0.86] px-5 backdrop-blur-md md:px-8">
            <div class="flex min-w-0 items-center gap-2 text-[13.5px] text-faint">
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

        <main class="mx-auto w-full max-w-[1080px] px-5 pb-24 pt-7 md:px-8">
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

{{-- Main Modal — conteúdo via AJAX (Modal.loadFromURLAndOpen) --}}
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

{{-- Side Panel — shell genérico, conteúdo via AJAX --}}
<div id="side-panel-overlay"
     class="pointer-events-none fixed inset-0 z-40 bg-black/30 opacity-0 transition-opacity duration-300"
     data-ak-panel-close></div>

<aside id="side-panel"
       class="fixed right-0 top-0 z-50 flex h-full w-96 translate-x-full flex-col bg-surface text-body shadow-2xl transition-transform duration-300">
    <div data-panel-placeholder class="flex flex-1 items-center justify-center">
        <div class="flex gap-1.5">
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:0s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.15s"></span>
            <span class="size-2 animate-bounce rounded-full bg-line-2" style="animation-delay:.3s"></span>
        </div>
    </div>
</aside>

</body>
</html>
