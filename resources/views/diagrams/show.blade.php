{{-- A diagram's own page: a thin top bar (name + status,
     a way back to the index) and the canvas filling everything below it.

     Fluid + full height, like the documentation editor: the canvas is the
     content, so it gets the whole viewport rather than a reading column. --}}
<x-layouts.layout :title="$title" :fluid="true">
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
            <div class="flex min-w-0 items-center gap-2">
                <a href="{{ route('diagrams.index') }}" title="Todos os diagramas"
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-field text-muted no-underline transition-colors hover:bg-raised hover:text-ink">
                    <x-heroicon-o-arrow-left class="size-4" />
                </a>
                <x-diagrams.meta :diagram="$diagram" />
            </div>

            <div class="flex shrink-0 items-center gap-2">
                {{-- Nothing about documentation here: prose CITES a drawing
                     with a `diagram` block, which lives in the text, so there
                     is no link for this side to show or edit. --}}
                @can('delete', $diagram)
                    {{-- `after=index` so the response navigates away: staying
                         on the page of a deleted diagram is a 404 on the next
                         click. --}}
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="diagram-page-delete"
                        data-ak-action="{{ route('diagrams.destroy', ['diagram' => $diagram, 'after' => 'index']) }}"
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
