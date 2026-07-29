<!DOCTYPE html>
<html lang="pt-BR">
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
<body class="min-h-screen bg-canvas font-sans text-body antialiased">

<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">

    <a href="{{ route('login.create') }}" class="mb-8 flex items-center gap-2.5 no-underline">
        <span class="flex size-9 items-center justify-center rounded-field bg-sidebar font-display text-base font-bold text-white">L</span>
        <span class="font-display text-xl font-semibold tracking-tight text-ink">{{ config('app.name') }}</span>
    </a>

    <div class="w-full max-w-sm rounded-card border border-line bg-surface p-8 shadow-card">
        {{ $slot }}
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
            <div data-timer class="h-full rounded-full bg-accent" style="width: 100%"></div>
        </div>
    </div>
</div>

</body>
</html>
