@php
    use App\Models\DocumentationGroup;
    use App\Models\Solution;

    // Every container that has documentation worth attaching, in one list.
    //
    // BOTH kinds are here on purpose. A Solution's own pages are the obvious
    // ones, but almost all of this inventory's documentation lives in
    // DocumentationGroups — the imported GitBook spaces ("Integrações Digibee",
    // "Dados • BigQuery • GCP"…), which are exactly what a flowSpec needs to
    // stand on. Listing only Solutions offered 4 of 617 documented pages, which
    // reads as "there is nothing to attach".
    //
    // `documentedPages` is the existing scope for "pages with real content": a
    // page with an empty body would be a heading with nothing under it.
    $documented = fn ($query) => $query->whereNotNull('documentation')->where('documentation', '<>', '');

    $containers = collect()
        ->concat(Solution::query()
            ->whereHas('pages', $documented)
            ->with(['documentedPages' => fn ($q) => $q->select('id', 'container_id', 'container_type', 'title', 'position')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Solution $s) => ['key' => "solution-{$s->id}", 'name' => $s->name, 'kind' => 'Solução', 'pages' => $s->documentedPages]))
        ->concat(DocumentationGroup::query()
            ->whereHas('pages', $documented)
            ->with(['documentedPages' => fn ($q) => $q->select('id', 'container_id', 'container_type', 'title', 'position')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (DocumentationGroup $g) => ['key' => "group-{$g->id}", 'name' => $g->name, 'kind' => 'Documentação', 'pages' => $g->documentedPages]))
        ->reject(fn (array $container) => $container['pages']->isEmpty())
        ->values();

    // There used to be a third section under these two, listing each
    // integration's own documentation. Integrations no longer hold any — a
    // drawing is a Diagram and carries no prose — so every attachable thing
    // here is a page in one of the containers above.
    $isAttached = fn (string $ref) => in_array($ref, $attached, true);
    $total = $containers->sum(fn (array $container) => $container['pages']->count());
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
                    Escolha as páginas e integrações que o especialista deve ler nesta conversa.
                </p>
            </div>
            <x-forms.button type="button" variant="ghost" data-ak-panel-close class="!px-2 !py-1" aria-label="Fechar">
                <x-heroicon-o-x-mark class="size-4" />
            </x-forms.button>
        </div>

        <div class="mt-3">
            <x-forms.input type="search" data-ak-fs-picker-search autofocus
                placeholder="Filtrar por título, sistema ou integração…" />
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
        @if ($total === 0)
            <x-ui.empty-state
                illustration="docs"
                illustration-class="max-w-[180px]"
                title="Nenhuma documentação para anexar"
                description="Escreva documentação em alguma solução ou integração para poder anexá-la aqui." />
        @else
            <div class="flex flex-col gap-2">
                @foreach ($containers as $container)
                    <x-flowspec.picker-group :title="$container['name']" :eyebrow="$container['kind']" :count="$container['pages']->count()">
                        @foreach ($container['pages'] as $page)
                            <x-flowspec.picker-row
                                :ref="'page:' . $page->id"
                                :title="$page->title"
                                :search="$container['name'] . ' ' . $page->title"
                                :attached="$isAttached('page:' . $page->id)"
                                :label="$container['name'] . ' — ' . $page->title" />
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
