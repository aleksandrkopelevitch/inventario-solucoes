<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
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
