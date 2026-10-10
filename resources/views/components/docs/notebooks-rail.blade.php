@props([
    'notebooks',
    'homeUrl',
])

{{-- The landing's rail: "Início" plus every published caderno, with a field
     that narrows the list as somebody types.

     The filter is `docs-switcher.js`, the one that already narrows the top
     bar's switcher — same hooks, same folding, same "Enter opens the first
     match". Two filters over the same list of cadernos must answer a query the
     same way, and the cheapest way to guarantee that is for them to be one.

     Unlike the switcher, the field is ALWAYS here, whatever the count: the rail
     is the landing's way into everything, and asking for a filter is what
     this rail was built for. --}}
<div data-ak-docs-switcher {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    {{-- Sticky inside the scrolling aside, so the field is still in reach
         after scrolling a long list. --}}
    <div class="sticky top-0 z-10 flex flex-col gap-2 bg-white pb-3 pt-10">
        <a href="{{ $homeUrl }}" aria-current="page"
           class="flex items-center gap-2 rounded-field bg-accent-soft px-2.5 py-1.5 text-[13.5px] font-semibold text-accent no-underline">
            <x-heroicon-o-home class="size-4 shrink-0" />
            Início
        </a>

        <p class="mt-3 flex items-baseline justify-between px-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted">
            Cadernos
            <span class="font-mono font-normal tracking-normal text-faint">{{ $notebooks->count() }}</span>
        </p>

        <div class="relative">
            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-faint" />
            <x-forms.input type="search" data-ak-docs-switcher-input
                placeholder="Filtrar cadernos…" class="!h-8 !pl-8 !text-sm"
                aria-label="Filtrar cadernos" autocomplete="off" />
        </div>
    </div>

    <nav class="flex flex-col gap-px" aria-label="Cadernos">
        @foreach ($notebooks as $notebook)
            {{-- The systems it documents ride in the filter label (and on a
                 second line): somebody looking for the Digibee manual types
                 "Digibee", not whatever the caderno was called. --}}
            <a href="{{ $notebook->knowledgeBaseUrl() }}"
               data-ak-docs-switcher-item
               data-ak-docs-switcher-label="{{ $notebook->name }} {{ $notebook->solutions->pluck('name')->join(' ') }}"
               class="group flex items-start gap-2 rounded-field px-2.5 py-1.5 no-underline transition-colors hover:bg-raised">
                <x-heroicon-o-book-open class="mt-0.5 size-4 shrink-0 text-faint transition-colors group-hover:text-accent" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13.5px] text-body group-hover:text-ink">{{ $notebook->name }}</span>
                    @if ($notebook->solutions->isNotEmpty())
                        <span class="block truncate text-[11px] text-faint">{{ $notebook->solutions->pluck('name')->join(' · ') }}</span>
                    @endif
                </span>
            </a>
        @endforeach
    </nav>

    <p data-ak-docs-switcher-empty @if ($notebooks->isNotEmpty()) hidden @endif class="px-2.5 py-6 text-center text-xs text-muted">
        {{ $notebooks->isEmpty() ? 'Nenhum caderno publicado ainda.' : 'Nenhum caderno encontrado.' }}
    </p>
</div>
