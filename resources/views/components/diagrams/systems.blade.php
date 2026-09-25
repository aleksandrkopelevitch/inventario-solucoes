@php
    $selected = $declared->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values();
@endphp

{{-- "Sistemas envolvidos" — the drawing's participants, in the top bar of its
     own page. A popover rather than a permanent strip: the canvas is what this
     screen is for, and the list is a fact somebody checks, not one they work
     in.

     The two halves are labelled because only one of them is editable here.
     DESENHADOS come from the chain (`SyncDiagramFromChain` derives them from
     the `system` blocks), so they are removed by editing the block, not by this
     form — the chips below would fight the derivation on the next mutation.
     DECLARADOS exist only as this statement, and they are what a process or
     data-flow diagram has: lanes and neutral steps name no solution, so without
     them the drawing reached neither the ecosystem map nor any solution's
     page. --}}
<div id="{{ $domId }}" class="relative shrink-0">
    <x-forms.button type="button" variant="ghost" data-ak-toggle="diagram-systems-panel"
        data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
        class="!h-8 !gap-1.5 !px-2 !text-xs !font-medium"
        aria-label="Sistemas envolvidos" title="Sistemas envolvidos">
        <x-heroicon-o-square-3-stack-3d class="size-4 text-muted" />
        <span class="hidden sm:inline">Sistemas</span>
        @if ($total)
            <span class="rounded-full bg-raised px-1.5 py-px text-[11px] font-semibold text-muted">{{ $total }}</span>
        @endif
    </x-forms.button>

    <div id="diagram-systems-panel"
        class="hidden absolute right-0 top-full z-30 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
        <h3 class="font-display text-sm font-semibold text-ink">Sistemas envolvidos</h3>
        <p class="mt-0.5 text-xs text-muted">
            É por esta lista que o diagrama aparece no mapa do ecossistema e na página de cada sistema.
        </p>

        <div class="mt-3">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-faint">Desenhados no canvas</span>
            @if ($drawn->isNotEmpty())
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($drawn as $solution)
                        <a href="{{ route('solutions.show', $solution) }}"
                            class="inline-flex items-center rounded-full bg-accent-soft px-2.5 py-1 text-xs font-medium text-ink no-underline ring-1 ring-accent-line hover:bg-accent-line">
                            {{ $solution->name }}
                        </a>
                    @endforeach
                </div>
            @else
                <p class="mt-1.5 text-xs text-muted">Nenhum bloco do desenho aponta para um sistema do catálogo.</p>
            @endif
        </div>

        <div class="mt-4 border-t border-line pt-3">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-faint">Declarados à mão</span>

            @if ($canEdit)
                <p class="mt-1 text-xs text-muted">
                    Para os desenhos cujos sistemas não são blocos — um processo ou fluxo de dados, feito de raias e
                    etapas.
                </p>

                {{-- The chips' hidden inputs submit as `solutions[i][value]`,
                     which is what SyncDiagramSystemsRequest normalizes. An empty
                     set is valid and means "nenhum sistema declarado". --}}
                <form id="diagram-systems-form" class="mt-2">
                    @csrf
                    @method('PATCH')

                    <x-forms.chips name="solutions" :items="$selected"
                        placeholder="Buscar sistema e pressionar Enter"
                        :search-url="route('solutions.search')" />

                    <x-forms.button data-ak-ajax="diagram-systems-form" data-ak-action="{{ $action }}"
                        class="mt-3 !h-9 !text-sm">
                        Salvar sistemas
                    </x-forms.button>
                </form>
            @elseif ($declared->isNotEmpty())
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($declared as $solution)
                        <span class="inline-flex items-center rounded-full bg-raised px-2.5 py-1 text-xs font-medium text-body">
                            {{ $solution->name }}
                        </span>
                    @endforeach
                </div>
            @else
                <p class="mt-1.5 text-xs text-muted">Nenhum sistema declarado.</p>
            @endif
        </div>
    </div>
</div>
