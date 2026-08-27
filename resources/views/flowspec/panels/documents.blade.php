@php
    use App\Models\Notebook;

    // Every caderno with documentation worth attaching, in one list.
    //
    // It used to be two lists — a Solution's own pages, and the standalone
    // groups — because there were two kinds of container. There is one now, and
    // the collapse fixed a real problem with the old shape: listing only
    // Solutions offered 4 of 617 documented pages, because almost all of this
    // inventory's documentation lives in the imported GitBook spaces. Those
    // spaces are cadernos, and so is everything else.
    //
    // `documentedPages` is the existing scope for "pages with real content": a
    // page with an empty body would be a heading with nothing under it.
    $notebooks = Notebook::query()
        ->whereHas('documentedPages')
        ->with([
            'documentedPages' => fn ($q) => $q->select('id', 'notebook_id', 'title', 'position'),
            'solutions:id,name',
        ])
        ->orderBy('name')
        ->get(['id', 'name'])
        ->map(fn (Notebook $notebook) => [
            'key'   => "notebook-{$notebook->id}",
            'name'  => $notebook->name,
            // The eyebrow says which systems this caderno describes, which is
            // what tells two similarly-named spaces apart when picking.
            'kind'  => $notebook->solutions->isNotEmpty()
                ? $notebook->solutions->pluck('name')->join(' · ')
                : 'Caderno',
            'pages' => $notebook->documentedPages,
        ])
        ->values();

    $isAttached = fn (string $ref) => in_array($ref, $attached, true);
    $total = $notebooks->sum(fn (array $notebook) => $notebook['pages']->count());
@endphp

{{-- The picker, in the generic side panel. A browsable list rather than a
     search-only field: the point is to see WHAT documentation exists and choose
     among it, the way a Claude project shows its knowledge.

     Everything is rendered at once and filtered in the browser rather than
     searched over the wire. There are hundreds of imported pages, so the groups
     start COLLAPSED and the filter opens the ones that match: what makes a list
     that long usable is instant narrowing, and a debounced round trip per
     keystroke is the one thing that would take that away.

     Nothing here posts on its own. "Anexar selecionados" hands the checked
     references to flowspec-chat.js, which either attaches them immediately (an
     open conversation) or stages them in the composer (a new one). --}}
<div data-ak-fs-picker-panel class="flex h-full flex-col">
    <div class="border-b border-line px-5 py-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-display text-base font-semibold text-ink">Documentos do inventário</h2>
                <p class="mt-0.5 text-xs text-muted">
                    Escolha as páginas que o especialista deve ler nesta conversa.
                </p>
            </div>
            <x-forms.button type="button" variant="ghost" data-ak-panel-close class="!px-2 !py-1" aria-label="Fechar">
                <x-heroicon-o-x-mark class="size-4" />
            </x-forms.button>
        </div>

        <div class="mt-3">
            <x-forms.input type="search" data-ak-fs-picker-search autofocus
                placeholder="Filtrar por título, caderno ou sistema…" />
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
        @if ($total === 0)
            <x-ui.empty-state
                illustration="docs"
                illustration-class="max-w-[180px]"
                title="Nenhuma documentação para anexar"
                description="Escreva documentação em algum caderno para poder anexá-la aqui." />
        @else
            <div class="flex flex-col gap-2">
                @foreach ($notebooks as $notebook)
                    <x-flowspec.picker-group :title="$notebook['name']" :eyebrow="$notebook['kind']" :count="$notebook['pages']->count()">
                        {{-- `:search` carries the caderno's own name AND the
                             solutions it documents, so a page is findable by the
                             system it describes even when the caderno is named
                             after a GitBook space nobody remembers. (The comment
                             sits OUTSIDE the tag: ComponentTagCompiler runs
                             before comments are stripped, so one in the
                             attribute area is parsed as markup.) --}}
                        @foreach ($notebook['pages'] as $page)
                            <x-flowspec.picker-row
                                :ref="'page:' . $page->id"
                                :title="$page->title"
                                :search="$notebook['name'] . ' ' . $notebook['kind'] . ' ' . $page->title"
                                :attached="$isAttached('page:' . $page->id)"
                                :label="$notebook['name'] . ' — ' . $page->title" />
                        @endforeach
                    </x-flowspec.picker-group>
                @endforeach

                <p data-ak-fs-picker-empty class="hidden py-6 text-center text-sm text-muted">Nada com esse nome.</p>
            </div>
        @endif
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
        <span data-ak-fs-picker-count class="text-xs text-muted">Nenhum selecionado</span>
        <x-forms.button type="button" data-ak-fs-picker-apply disabled>Anexar selecionados</x-forms.button>
    </div>
</div>
