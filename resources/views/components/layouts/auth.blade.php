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

    {{-- The iSol wordmark, on a transparent background (resources/img). --}}
    <a href="{{ route('login.create') }}" class="mb-8 block no-underline">
        <img src="{{ Vite::asset('resources/img/logo-isol-horizontal.png') }}" alt="iSol — {{ config('app.name') }}" class="h-16 w-auto">
    </a>

    <div class="w-full max-w-sm rounded-card border border-line bg-surface p-8 shadow-card">
        {{ $slot }}
    </div>

</div>

<x-layouts.alert-modal />

<x-layouts.toast />


</body>
</html>
