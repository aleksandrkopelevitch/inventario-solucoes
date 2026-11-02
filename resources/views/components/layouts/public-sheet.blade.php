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
