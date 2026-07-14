<x-layouts.layout title="Visão geral">
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Olá, {{ $firstName }}</h1>
        <p class="mt-1 text-sm text-muted">Portfólio de soluções da Leo Madeiras e estado da documentação.</p>
    </div>

    <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($metrics as $metric)
            <div class="rounded-card border border-line bg-surface p-5 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                <div class="flex items-center gap-2 text-xs text-muted">
                    <x-dynamic-component :component="'heroicon-o-'.$metric['icon']" class="size-4 text-accent" />
                    {{ $metric['label'] }}
                </div>
                <div class="mt-2 font-display text-[34px] font-semibold leading-none text-ink">
                    {{ $metric['value'] }}
                    <span class="ml-1 font-sans text-[13px] font-semibold text-muted">{{ $metric['detail'] }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-5 rounded-card border border-[#eccccc] bg-crit-soft p-5">
        <div class="font-display text-[40px] font-semibold leading-none text-crit">0 / 81</div>
        <div class="min-w-[200px] flex-1">
            <b class="text-[15px] text-ink">Lacuna de documentação</b>
            <p class="mt-0.5 text-[13px] text-muted">Nenhuma solução possui arquitetura macro, componentes detalhados ou fluxos de negócio documentados. A cobertura será medida na Etapa 6 (F7).</p>
        </div>
    </div>

    <div class="mt-8 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Etapa 1</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Fundação de dados concluída</h2>
        </div>
        <p class="mt-2 text-sm text-muted">88 soluções (81 do inventário + 7 planejadas), 55 empresas, 106 pessoas e 10 integrações importadas. As telas de catálogo, integrações, pessoas e mapa entram nas próximas etapas.</p>
    </div>
</x-layouts.layout>
