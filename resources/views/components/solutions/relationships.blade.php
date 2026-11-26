{{-- Left column of the solution page's card (frame in solutions/show.blade.php):
     who this solution talks to, as the documentation's UML diagrams say it.
     One row per pair, THIS solution always on the left, so "──▶" always
     reads "esta chama a outra". See App\View\Components\Solutions\Relationships. --}}
<div id="{{ $domId }}" class="flex min-w-0 flex-col p-6" aria-label="Relacionamentos da solução">
    <div class="flex items-center gap-2.5">
        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent text-white">
            <x-heroicon-o-arrows-right-left class="size-4" />
        </span>
        <h2 class="font-display text-[18px] font-semibold text-ink">Relacionamentos</h2>
        @if ($relationships->isNotEmpty())
            <span class="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">{{ $relationships->count() }}</span>
        @endif
    </div>

    <p class="mt-1.5 text-sm text-muted">Soluções com que esta se comunica, segundo os diagramas UML da documentação.</p>

    <ul class="mt-4 flex flex-col gap-2">
        @forelse ($relationships as $relationship)
            @php
                $out = in_array($relationship->direction, [\App\Enums\RelationshipDirection::Outgoing, \App\Enums\RelationshipDirection::Both], true);
                $in = in_array($relationship->direction, [\App\Enums\RelationshipDirection::Incoming, \App\Enums\RelationshipDirection::Both], true);
                $undirected = $relationship->direction === \App\Enums\RelationshipDirection::Undirected;
            @endphp
            <li class="rounded-field border border-line bg-surface px-3.5 py-2.5" data-relationship="{{ $relationship->other->slug }}">
                <div class="flex min-w-0 items-center gap-2">
                    <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="sm" class="!size-7" />
                    {{-- Below sm this solution is its logo alone: the page
                         already says which one it is, and the row's room
                         belongs to the counterpart's name. --}}
                    <span class="min-w-0 max-w-[30%] truncate text-sm text-muted max-sm:hidden">{{ $solution->name }}</span>

                    {{-- The arrow. Its words are the tooltip and the sr-only
                         sentence; the SVG itself is decoration. --}}
                    <span class="inline-flex shrink-0" title="{{ $relationship->sentence() }}" data-direction="{{ $relationship->direction->value }}">
                        <svg viewBox="0 0 40 12" class="h-3 w-10 {{ $undirected ? 'text-faint' : 'text-accent' }}" aria-hidden="true">
                            <line x1="{{ $in ? 7 : 1 }}" y1="6" x2="{{ $out ? 33 : 39 }}" y2="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                            @if ($out)
                                <path d="M32 1.5 L39 6 L32 10.5 Z" fill="currentColor" />
                            @endif
                            @if ($in)
                                <path d="M8 1.5 L1 6 L8 10.5 Z" fill="currentColor" />
                            @endif
                        </svg>
                        <span class="sr-only">{{ $relationship->sentence() }}</span>
                    </span>

                    <x-ui.logo :name="$relationship->other->name" :src="$relationship->other->logo_path" size="sm" class="!size-7" />
                    <a href="{{ route('solutions.show', $relationship->other) }}"
                       class="min-w-0 flex-1 truncate text-sm font-medium text-ink hover:text-accent hover:underline">{{ $relationship->other->name }}</a>
                </div>

                @if (! $relationship->direct && $relationship->via !== [])
                    <p class="mt-1.5 flex min-w-0 items-center gap-1.5 text-xs text-muted">
                        <span>via</span>
                        @foreach ($relationship->via as $platform)
                            <x-ui.logo :name="$platform->name" :src="$platform->logo_path" size="sm" class="!size-5 !p-0.5" />
                            <span class="truncate">{{ $platform->name }}</span>
                            @if (! $loop->last)
                                <span aria-hidden="true">›</span>
                            @endif
                        @endforeach
                    </p>
                @endif

                @if ($relationship->labels !== [])
                    <p class="mt-1 truncate font-mono text-xs text-muted">{{ implode(' · ', array_slice($relationship->labels, 0, 3)) }}</p>
                @endif

                @if ($showEvidence && $relationship->evidence !== [])
                    {{-- A native disclosure: no script, keyboard and screen
                         reader for free. --}}
                    <details class="group/evidence mt-1.5">
                        <summary class="cursor-pointer list-none text-xs text-accent hover:underline">
                            Onde está documentado ({{ count($relationship->evidence) }})
                            <x-heroicon-o-chevron-down class="inline size-3 transition-transform group-open/evidence:rotate-180" />
                        </summary>
                        <ul class="mt-1.5 flex flex-col gap-1 border-l border-line pl-3">
                            @foreach ($relationship->evidence as $evidence)
                                <li class="min-w-0 truncate text-xs">
                                    <a href="{{ route('notebooks.pages.edit', [$evidence['page']->notebook, $evidence['page']]) }}#uml-figure-{{ $evidence['position'] }}"
                                       class="text-ink hover:text-accent hover:underline">
                                        {{ $evidence['page']->notebook->name }} › {{ $evidence['page']->title }}
                                    </a>
                                    <span class="text-muted">— {{ $evidence['title'] ?? \App\Support\Documentation\UmlDiagram::TYPES[$evidence['type']] ?? 'Diagrama UML' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </li>
        @empty
            <li>
                <x-ui.empty-state illustration="diagrams" illustration-class="max-w-[268px]"
                    title="Nenhum relacionamento documentado"
                    description="Os relacionamentos vêm dos diagramas UML das páginas dos cadernos. Num diagrama, vincule um participante a uma solução com “solution”." />
            </li>
        @endforelse
    </ul>
</div>
