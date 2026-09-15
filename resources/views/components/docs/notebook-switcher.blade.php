@props([
    'notebooks',
    'current' => null,
    'heading' => '',
    'homeUrl' => null,
])

{{-- The caderno switcher — the one affordance `/docs` has that the magic link
     cannot: a token grants exactly one caderno, so there is nothing there to
     switch to.

     It REPLACES the caderno's name in the top bar rather than sitting beside
     it, and the name keeps its exact type and position. The two surfaces have
     to read as one screen, so the switcher has to look like the heading it
     grew out of, not like a control bolted next to it.

     `toggle.js` opens it (`data-ak-toggle-blur` closes it on an outside click)
     — no module of its own for that. `docs-switcher.js` only owns the filter
     field, which a caderno list of any size needs and a short one costs
     nothing. --}}
<div class="relative min-w-0">
    <button type="button"
            data-ak-toggle="docs-notebook-switcher"
            data-ak-toggle-classes="hidden"
            data-ak-toggle-blur="true"
            aria-haspopup="true"
            class="group flex min-w-0 max-w-full cursor-pointer items-center gap-1.5 rounded-field px-1.5 py-1 -ml-1.5 text-left transition-colors hover:bg-raised">
        <span class="truncate font-display text-base font-semibold leading-tight text-ink">{{ $heading }}</span>
        <x-heroicon-o-chevron-up-down class="size-4 shrink-0 text-faint transition-colors group-hover:text-ink" />
    </button>

    <div id="docs-notebook-switcher" data-ak-docs-switcher
         class="absolute left-0 top-full z-40 mt-1.5 hidden w-80 max-w-[calc(100vw_-_2rem)] overflow-hidden rounded-card border border-line bg-surface shadow-card-hover">

        @if ($notebooks->count() > 6)
            {{-- Only past the point where scanning stops being instant. A
                 filter field over five rows is chrome asking to be used. --}}
            <div class="border-b border-line p-2">
                <x-forms.input type="search" data-ak-docs-switcher-input
                    placeholder="Filtrar cadernos…" class="!h-8 !text-sm"
                    aria-label="Filtrar cadernos" />
            </div>
        @endif

        <div class="max-h-80 overflow-y-auto p-1">
            @foreach ($notebooks as $notebook)
                <a href="{{ $notebook->knowledgeBaseUrl() }}"
                   data-ak-docs-switcher-item
                   data-ak-docs-switcher-label="{{ $notebook->name }} {{ $notebook->solutions->pluck('name')->join(' ') }}"
                   @class([
                       'flex items-start gap-2 rounded-field px-2.5 py-2 no-underline transition-colors',
                       'bg-accent-soft' => $current?->is($notebook),
                       'hover:bg-raised' => ! $current?->is($notebook),
                   ])>
                    <x-heroicon-o-book-open @class(['mt-0.5 size-4 shrink-0', 'text-accent' => $current?->is($notebook), 'text-faint' => ! $current?->is($notebook)])/>
                    <span class="min-w-0 flex-1">
                        <span @class(['block truncate text-[13.5px]', 'font-semibold text-accent' => $current?->is($notebook), 'text-body' => ! $current?->is($notebook)])>{{ $notebook->name }}</span>
                        @if ($notebook->solutions->isNotEmpty())
                            {{-- The systems it documents. The same line the
                                 catalog card carries, and the reason somebody
                                 finds the right caderno without knowing what it
                                 was named. --}}
                            <span class="mt-0.5 block truncate text-[11px] text-muted">{{ $notebook->solutions->pluck('name')->join(' · ') }}</span>
                        @endif
                    </span>
                </a>
            @endforeach

            {{-- Shown only while a filter has excluded everything. The list
                 itself is never empty here: this screen is only reachable
                 through a published caderno. --}}
            <p data-ak-docs-switcher-empty hidden class="px-2.5 py-6 text-center text-xs text-muted">
                Nenhum caderno encontrado.
            </p>
        </div>

        @if ($homeUrl)
            <div class="border-t border-line p-1">
                <a href="{{ $homeUrl }}" class="flex items-center gap-2 rounded-field px-2.5 py-2 text-[13px] text-muted no-underline transition-colors hover:bg-raised hover:text-ink">
                    <x-heroicon-o-squares-2x2 class="size-4 shrink-0" />
                    Ver todos os cadernos
                </a>
            </div>
        @endif
    </div>
</div>
