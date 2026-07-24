@php
    // Coverage tone → literal Tailwind classes. Built as a map (not
    // `bg-{{ $tone }}`) so the JIT scanner actually emits bg-hot / bg-crit,
    // which appear nowhere else as a solid fill — only their -soft variants do.
    $toneClasses = [
        'accent' => ['bar' => 'bg-accent', 'text' => 'text-accent'],
        'hot'    => ['bar' => 'bg-hot',    'text' => 'text-hot'],
        'crit'   => ['bar' => 'bg-crit',   'text' => 'text-crit'],
    ];
@endphp

<x-layouts.layout title="Visão geral">
    {{-- Hero — with the subtle lime "morning light" wash behind the greeting. --}}
    <div class="relative mb-6 animate-ak-rise">
        <div aria-hidden="true" class="pointer-events-none absolute -inset-x-6 -top-12 h-44 bg-[radial-gradient(120%_130%_at_0%_0%,rgba(170,219,30,0.15),transparent_58%)]"></div>
        <div class="relative">
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Olá, {{ $firstName }}</h1>
            <p class="mt-1 text-sm text-muted">Portfólio de soluções da Leo Madeiras e estado da documentação.</p>
        </div>
    </div>

    {{-- Live inventory snapshot — each card is a doorway into its section --}}
    <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($metrics as $i => $metric)
            <a href="{{ $metric['url'] }}"
               class="group animate-ak-rise rounded-card border border-line bg-surface p-5 no-underline shadow-card transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-accent-line hover:shadow-[0_8px_24px_-8px_rgba(20,58,34,0.18)]"
               style="animation-delay: {{ $i * 60 }}ms">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs font-medium text-muted">
                        <x-dynamic-component :component="'heroicon-o-'.$metric['icon']" class="size-4 text-accent" />
                        {{ $metric['label'] }}
                    </div>
                    <x-heroicon-o-arrow-up-right class="size-3.5 text-faint opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
                </div>
                <div class="mt-2 font-display text-[34px] font-semibold leading-none text-ink">
                    {{ $metric['value'] }}
                    <span class="ml-1 font-sans text-[13px] font-medium text-muted">{{ $metric['detail'] }}</span>
                </div>
            </a>
        @endforeach
    </div>

    <div class="mt-5 grid gap-3.5 lg:grid-cols-5">
        {{-- Documentation coverage — real, content-based, honest about the gap --}}
        <div class="animate-ak-rise rounded-card border border-line bg-surface p-6 shadow-card lg:col-span-3"
             style="animation-delay: 240ms">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-[22px] font-semibold text-ink">Cobertura de documentação</h2>
                    <p class="mt-0.5 text-[13px] text-muted">Medida pelo conteúdo real de cada item.</p>
                </div>
                <a href="{{ route('documentation.index') }}"
                   class="inline-flex shrink-0 items-center gap-1 rounded-field px-2.5 py-1.5 text-[13px] font-semibold text-accent no-underline transition-colors hover:bg-accent-soft">
                    Abrir hub <x-heroicon-o-arrow-right class="size-3.5" />
                </a>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($coverageBars as $j => $bar)
                    @php
                        $pct = (int) $bar['percent'];
                        $tone = $pct >= 60 ? 'accent' : ($pct >= 25 ? 'hot' : 'crit');
                        $cls = $toneClasses[$tone];
                    @endphp
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-[13px]">
                            <span class="flex items-center gap-1.5 font-medium text-body">
                                <x-dynamic-component :component="'heroicon-o-'.$bar['icon']" class="size-3.5 text-muted" />
                                {{ $bar['label'] }}
                            </span>
                            <span class="font-mono text-xs text-muted">
                                <b class="text-ink">{{ $bar['documented'] }}</b> / {{ $bar['total'] }}
                                <span class="ml-1 text-faint">·</span>
                                <b class="{{ $cls['text'] }}">{{ $pct }}%</b>
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-raised">
                            <div class="animate-ak-grow h-full origin-left rounded-full {{ $cls['bar'] }}"
                                 style="width: {{ max($pct, 2) }}%; animation-delay: {{ 380 + $j * 120 }}ms"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-5 border-t border-line pt-4 text-[13px] leading-relaxed text-muted">
                A maior parte do inventário ainda não tem arquitetura, componentes ou fluxos documentados.
                O <a href="{{ route('documentation.index') }}" class="font-medium text-accent hover:underline">hub de documentação</a>
                mostra exatamente o que falta, solução por solução.
            </p>
        </div>

        {{-- Quick access into the work --}}
        <div class="animate-ak-rise rounded-card border border-line bg-surface p-6 shadow-card lg:col-span-2"
             style="animation-delay: 300ms">
            <h2 class="font-display text-[22px] font-semibold text-ink">Atalhos</h2>
            <p class="mt-0.5 text-[13px] text-muted">Ir direto ao ponto.</p>

            <div class="mt-4 flex flex-col gap-2">
                @foreach ($shortcuts as $shortcut)
                    <a href="{{ $shortcut['url'] }}"
                       class="group flex items-center gap-3 rounded-field border border-line p-3 no-underline transition-[background-color,border-color] duration-150 hover:border-accent-line hover:bg-accent-soft">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-field bg-accent-soft text-accent transition-colors group-hover:bg-accent group-hover:text-white">
                            <x-dynamic-component :component="'heroicon-o-'.$shortcut['icon']" class="size-[18px]" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-ink">{{ $shortcut['label'] }}</span>
                            <span class="block truncate text-xs text-muted">{{ $shortcut['detail'] }}</span>
                        </span>
                        <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-accent" />
                    </a>
                @endforeach

                @if ($flowspecCount > 0)
                    <p class="mt-1 px-1 text-xs text-muted">
                        <b class="font-semibold text-ink">{{ $flowspecCount }}</b>
                        {{ \Illuminate\Support\Str::plural('flowSpec', $flowspecCount) }} já {{ $flowspecCount === 1 ? 'gerado' : 'gerados' }} no assistente.
                    </p>
                @endif
            </div>
        </div>
    </div>
</x-layouts.layout>
