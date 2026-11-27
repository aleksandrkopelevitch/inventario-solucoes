{{-- Left column of the solution page's card (frame in solutions/show.blade.php):
     who this solution talks to — from the documentation's UML diagrams and from
     the interconnections people recorded. One row per pair, THIS solution
     always on the left, so "──▶" always reads "esta chama a outra". Edited
     inline by a catalog Editor. See App\View\Components\Solutions\Relationships. --}}
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

    <p class="mt-1.5 text-sm text-muted">Soluções com que esta se comunica — dos diagramas UML da documentação e das interconexões cadastradas.</p>

    <ul class="mt-4 flex flex-col gap-2">
        @forelse ($relationships as $relationship)
            @php
                $out = in_array($relationship->direction, [\App\Enums\RelationshipDirection::Outgoing, \App\Enums\RelationshipDirection::Both], true);
                $in = in_array($relationship->direction, [\App\Enums\RelationshipDirection::Incoming, \App\Enums\RelationshipDirection::Both], true);
                $undirected = $relationship->direction === \App\Enums\RelationshipDirection::Undirected;
                $other = $relationship->other;
                $manual = $relationship->manual;
                $source = $relationship->source();

                // A manual row re-points its counterpart and changes its
                // direction; a documented row records the override, so only the
                // direction is asked — the counterpart is what the diagram says.
                $directionField = [
                    'name' => 'direction', 'type' => 'select', 'options' => $directionOptions, 'nullable' => false, 'label' => 'Sentido',
                    'value' => $undirected ? \App\Enums\RelationshipDirection::Both->value : $relationship->direction->value,
                    'class' => $manual ? 'w-52 shrink-0' : 'min-w-0 flex-1',
                ];
                $fields = $manual
                    ? [
                        ['name' => 'other_solution_id', 'type' => 'select', 'nullable' => false, 'label' => 'Sistema', 'value' => $other->getKey(),
                         'options' => array_merge([['value' => $other->getKey(), 'label' => $other->name]], $freeOptions), 'class' => 'min-w-0 flex-1'],
                        $directionField,
                    ]
                    : [$directionField];
                $action = $manual
                    ? route('solutions.relationships.update', [$solution, $manual])
                    : route('solutions.relationships.override', [$solution, 'other_solution_id' => $other->getKey()]);

                $recordedBy = $manual
                    ? 'Cadastrada por ' . ($manual->creator?->name ?? 'autor não registrado') . ' em ' . $manual->created_at->format('d/m/Y')
                    : null;
                $removeConfirm = $source === 'adjusted'
                    ? 'Voltar ao que a documentação diz (' . $relationship->documented->sentence($solution->name, $other->name) . ')?'
                    : 'Remover a interconexão entre ' . $solution->name . ' e ' . $other->name . '?';
            @endphp
            <li class="group/row rounded-field border border-line bg-surface px-3.5 py-2.5" data-relationship="{{ $other->slug }}">
                <div class="flex min-w-0 items-center gap-2">
                    <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="sm" class="!size-7" />
                    {{-- Below sm this solution is its logo alone: the page
                         already says which one it is, and the row's room
                         belongs to the counterpart's name. --}}
                    <span class="min-w-0 max-w-[30%] truncate text-sm text-muted max-sm:hidden">{{ $solution->name }}</span>

                    {{-- The arrow and the counterpart are the editable datum.
                         The counterpart's page is reached through the ↗, and
                         its name is plain text, as the inline-edit rule asks. --}}
                    <x-ui.inline-edit :fields="$fields" :action="$canEdit ? $action : null" :method="$manual ? 'PATCH' : 'PUT'"
                                      :editable="$canEdit" :link="route('solutions.show', $other)" link-label="solução"
                                      label="Interconexão" edit-class="w-full" class="min-w-0 flex-1">
                        <span class="flex min-w-0 items-center gap-2">
                            {{-- The arrow's words are the tooltip and the sr-only
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
                            <x-ui.logo :name="$other->name" :src="$other->logo_path" size="sm" class="!size-7" />
                            <span class="min-w-0 truncate text-sm font-medium text-ink">{{ $other->name }}</span>
                        </span>
                    </x-ui.inline-edit>

                    @if ($canEdit && $manual)
                        <x-ui.row-remove :id="'solution-relationship-remove-' . $manual->getKey()"
                                         :action="route('solutions.relationships.destroy', [$solution, $manual])"
                                         :confirm="$removeConfirm"
                                         :label="($source === 'adjusted' ? 'Voltar ao documentado: ' : 'Remover interconexão com ') . $other->name" />
                    @endif
                </div>

                {{-- Where the row comes from, under it rather than beside the
                     name: inline, a badge ate the room the counterpart's name
                     and the open editor need. --}}
                @if ($source !== 'documented' || (! $relationship->direct && $relationship->via !== []))
                    <p class="mt-1.5 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                        @if ($source === 'manual')
                            <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-[11px] font-medium text-muted" title="{{ $recordedBy }}">manual</span>
                        @elseif ($source === 'adjusted')
                            <span class="shrink-0 rounded-full bg-cat-amber-soft px-2 py-0.5 text-[11px] font-medium text-cat-amber-ink"
                                  title="{{ $recordedBy }}">ajustado manualmente</span>
                            <span class="min-w-0 truncate">documentado: “{{ $relationship->documented->sentence($solution->name, $other->name) }}”</span>
                        @endif
                        {{-- One "via" per route, its platforms in the order a
                             call crosses them from this solution. --}}
                        @if (! $relationship->direct)
                            @foreach ($relationship->via as $route)
                                <span class="inline-flex min-w-0 items-center gap-1.5">
                                    <span>via</span>
                                    @foreach ($route as $platform)
                                        <x-ui.logo :name="$platform->name" :src="$platform->logo_path" size="sm" class="!size-5 !p-0.5" />
                                        <span class="truncate">{{ $platform->name }}</span>
                                        @if (! $loop->last)
                                            <span aria-hidden="true">›</span>
                                        @endif
                                    @endforeach
                                </span>
                            @endforeach
                        @endif
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
                    description="Os relacionamentos vêm dos diagramas UML das páginas dos cadernos (vincule um participante a uma solução com “solution”) ou das interconexões cadastradas aqui." />
            </li>
        @endforelse
    </ul>

    @if ($canEdit && filled($freeOptions))
        <x-ui.inline-edit method="POST" :action="route('solutions.relationships.store', $solution)"
                         :fields="[
                             ['name' => 'other_solution_id', 'type' => 'select', 'options' => $freeOptions, 'label' => 'Sistema', 'empty' => 'Escolha um sistema', 'class' => 'min-w-0 flex-1'],
                             ['name' => 'direction', 'type' => 'select', 'options' => $directionOptions, 'value' => \App\Enums\RelationshipDirection::Outgoing->value, 'nullable' => false, 'label' => 'Sentido', 'class' => 'w-52 shrink-0'],
                         ]"
                         label="interconexão" edit-class="mt-3 w-full max-w-full" class="mt-3 block">
            <x-ui.add-chip>Adicionar interconexão</x-ui.add-chip>
        </x-ui.inline-edit>
    @endif
</div>
