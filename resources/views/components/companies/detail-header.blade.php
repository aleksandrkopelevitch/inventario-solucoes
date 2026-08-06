<div id="{{ $domId }}" class="overflow-hidden rounded-bento border border-line bg-surface shadow-card">
    {{-- Identity strip on the gradient — same pattern as
         solutions/detail-header.blade.php. --}}
    <div class="relative flex flex-wrap items-start gap-4 p-6"
         style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%)">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
             style="background: linear-gradient(90deg, var(--color-glow-a), var(--color-accent), var(--color-ink))"></div>

        <x-ui.logo :name="$company->name" :src="$company->logo_path" size="lg" class="relative shadow-sm" />
        <div class="relative min-w-0 flex-1">
            <h1 class="font-display text-[28px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">{{ $company->name }}</h1>
            <p class="mt-0.5 text-sm text-[color:var(--color-glow-ink)]/70">{{ $company->kind->label() }}</p>
            @if ($company->website)
                <a href="{{ $company->website }}" target="_blank" rel="noopener" class="mt-1 inline-block text-sm text-[color:var(--color-glow-ink)]/80 hover:text-[color:var(--color-glow-ink)] hover:underline">{{ $company->website }}</a>
            @endif
        </div>
        @can('update', $company)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('companies.edit', $company) }}"
               class="relative inline-flex items-center gap-2 rounded-field border border-white/50 bg-white/60 px-3 py-1.5 text-sm font-semibold text-[color:var(--color-glow-ink)] backdrop-blur hover:bg-white/90">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>
    @if ($company->notes)
        <p class="border-t border-line p-6 text-sm text-body">{{ $company->notes }}</p>
    @endif
</div>
