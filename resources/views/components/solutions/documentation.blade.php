<div id="{{ $domId }}" class="mt-5 rounded-card border border-line bg-surface p-6 shadow-card">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Docs</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Documentação</h2>
        </div>

        @can('update', $solution)
            <a href="{{ $editUrl }}"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                <x-heroicon-o-pencil-square class="size-4" />
                {{ $pages->isNotEmpty() ? 'Editar documentação' : 'Adicionar documentação' }}
            </a>
        @endcan
    </div>

    @if ($pages->isNotEmpty())
        <div class="mt-4 divide-y divide-line rounded-field border border-line">
            @foreach ($pages as $page)
                <a href="{{ $page['url'] }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm no-underline hover:bg-raised">
                    <span @class(['font-medium text-ink' => $page['hasContent'], 'italic text-muted' => ! $page['hasContent']])>
                        {{ $page['title'] }}
                    </span>
                    @unless ($page['hasContent'])
                        <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-xs text-muted">Vazia</span>
                    @endunless
                </a>
            @endforeach
        </div>
    @else
        <p class="mt-4 rounded-field border border-dashed border-line px-4 py-8 text-center text-sm text-muted">
            Nenhuma documentação cadastrada ainda.
        </p>
    @endif
</div>
