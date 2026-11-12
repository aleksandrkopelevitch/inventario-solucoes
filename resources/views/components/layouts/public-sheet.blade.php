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
    {{-- Inter's latin file, preloaded: it is what nearly every glyph on the
         page is set in, and without this the browser only discovers it after
         parsing the whole stylesheet. Just the regular face — the italic and
         the non-latin "rest" files are fetched only when such text is on screen
         (unicode-range in app.css). Space Mono is self-hosted too since
         2026-11-12; the Google Fonts stylesheet it came from was a
         render-blocking request to a third party on every page. --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin href="{{ Vite::asset('resources/fonts/InterVariable-latin.woff2') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-dvh flex-col bg-canvas font-sans text-[14.5px] text-body antialiased">

    <header class="flex shrink-0 items-center gap-3 border-b border-line bg-white px-4 py-3 sm:px-5">
        <img src="{{ Vite::asset('resources/img/logo-isol-icone.png') }}" alt="iSol" class="size-8 shrink-0 rounded-field">
        <p class="truncate font-display text-base font-semibold leading-tight text-ink">Inventário de Soluções · Leo Madeiras</p>
        <span class="ml-auto shrink-0 rounded-full border border-line bg-canvas px-2 py-0.5 text-[11px] font-medium text-muted">Somente leitura</span>
    </header>

    <div class="flex min-h-0 flex-1 flex-col">
        {{ $slot }}
    </div>

    <x-layouts.toast />
</body>
</html>
