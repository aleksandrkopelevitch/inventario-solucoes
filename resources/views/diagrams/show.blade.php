{{-- A diagram's own page: a thin top bar (name + status, where it's explained,
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
                {{-- The pages that explain this drawing, as links. This is the
                     diagram's half of the 1..N relation: the link itself is
                     authored from the PAGE (a page has one diagram), so there
                     is nothing to edit here — only somewhere to go. --}}
                @if ($diagram->pages->isNotEmpty())
                    @foreach ($diagram->pages as $page)
                        <a href="{{ route('notebooks.pages.edit', [$page->notebook, $page]) }}"
                            class="inline-flex max-w-52 items-center gap-1.5 rounded-field border border-line bg-surface px-2.5 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                            <x-heroicon-o-document-text class="size-3.5 shrink-0 text-faint" />
                            <span class="truncate">{{ $page->title }}</span>
                        </a>
                    @endforeach
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-field border border-dashed border-line px-2.5 py-1.5 text-xs text-faint">
                        <x-heroicon-o-link-slash class="size-3.5" />
                        Sem página vinculada
                    </span>
                @endif

                @can('delete', $diagram)
                    {{-- `after=index` so the response navigates away: staying
                         on the page of a deleted diagram is a 404 on the next
                         click. --}}
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="diagram-page-delete"
                        data-ak-action="{{ route('diagrams.destroy', ['diagram' => $diagram, 'after' => 'index']) }}"
                        data-ak-confirm="Excluir o diagrama &quot;{{ $diagram->name }}&quot;? As páginas vinculadas continuam existindo, apenas sem diagrama."
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
