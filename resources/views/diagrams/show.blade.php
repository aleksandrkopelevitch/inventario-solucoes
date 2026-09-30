{{-- A diagram's own page: a thin top bar (name + status, and the way back to
     the CADERNO it belongs to) and the canvas filling everything below it.

     Fluid + full height, like the documentation editor: the canvas is the
     content, so it gets the whole viewport rather than a reading column. --}}
<x-layouts.layout :title="$title" :fluid="true">
    <div class="flex min-h-0 flex-1 flex-col">
        {{-- `relative z-40` ranks the BAR, not what it opens — the same rule
             the documentation reader's three regions follow. The canvas below
             is full of absolutely positioned overlays up to `z-30`, and it
             comes later in the DOM, so a popover dropping out of this bar with
             a z-index of its own would tie with them and lose. --}}
        <div class="relative z-40 flex flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
                {{-- Back to the caderno, not to the diagrams index: a drawing is
                     opened from its caderno (the rail's "Diagramas do caderno",
                     or "Desenhar esta página"), and the index is one list among
                     three that show it. --}}
                <a href="{{ route('notebooks.show', $diagram->notebook) }}" title="Voltar ao caderno {{ $diagram->notebook->name }}"
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-field text-muted no-underline transition-colors hover:bg-raised hover:text-ink">
                    <x-heroicon-o-arrow-left class="size-4" />
                </a>
                <a href="{{ route('notebooks.show', $diagram->notebook) }}"
                    class="hidden max-w-[16rem] shrink-0 items-center gap-1.5 truncate text-xs font-medium text-muted no-underline hover:text-ink sm:inline-flex">
                    <x-heroicon-o-book-open class="size-4 shrink-0 text-accent" />
                    <span class="truncate">{{ $diagram->notebook->name }}</span>
                </a>
                <span class="hidden text-faint sm:inline" aria-hidden="true">/</span>
                <x-diagrams.meta :diagram="$diagram" />
            </div>

            <div class="flex shrink-0 items-center gap-2">
                {{-- Which systems the drawing concerns, and the only place the
                     DECLARED ones are edited — the drawn ones come from the
                     chain. A diagram made of lanes and neutral steps (every
                     generated process and data flow) has no `system` block to
                     derive anything from, so without this it reached neither
                     the ecosystem map nor any solution's page. --}}
                <x-diagrams.systems :diagram="$diagram" />

                {{-- Nothing about documentation here: prose CITES a drawing
                     with a `diagram` block, which lives in the text, so there
                     is no link for this side to show or edit. --}}
                @can('delete', $diagram)
                    {{-- `after=notebook` so the response navigates away, back to
                         the caderno: staying on the page of a deleted diagram
                         is a 404 on the next click. --}}
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="diagram-page-delete"
                        data-ak-action="{{ route('diagrams.destroy', ['diagram' => $diagram, 'after' => 'notebook']) }}"
                        data-ak-confirm="Excluir o diagrama &quot;{{ $diagram->name }}&quot;? Páginas que citam este desenho continuam existindo — a citação passa a mostrar que ele foi removido."
                        title="Excluir diagrama"
                        class="!p-1.5 text-muted hover:!text-crit">
                        <x-heroicon-o-trash class="size-4" />
                    </x-forms.button>
                    <form id="diagram-page-delete" class="hidden">
                        @csrf
                        @method('DELETE')
                    </form>
                @endcan
            </div>
        </div>

        <x-diagrams.workspace :diagram="$diagram" />
    </div>
</x-layouts.layout>
