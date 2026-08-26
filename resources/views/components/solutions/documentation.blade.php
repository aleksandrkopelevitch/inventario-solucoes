{{-- Right column of the solution's "integrações + documentação" card (frame in
     solutions/show.blade.php). The divider between the two columns rides here
     rather than on the frame: this element is the updatable slot, so it's
     re-rendered identically on every swap, while a wrapper around it would be
     one more node between the grid and the column. --}}
<div id="{{ $domId }}" class="flex min-w-0 flex-col border-t border-line p-6 lg:border-l lg:border-t-0">
    <div class="flex items-center gap-2.5">
        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent text-white">
            <x-heroicon-o-document-text class="size-4" />
        </span>
        <h2 class="font-display text-[18px] font-semibold text-ink">Documentação</h2>
        @if ($pages->isNotEmpty())
            <span class="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">{{ $pages->count() }}</span>
        @endif

        @if ($pages->isNotEmpty())
            <a href="{{ $editUrl }}"
                class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                <x-heroicon-o-pencil-square class="size-3.5" />
                Abrir editor
            </a>
        @endif
    </div>

    <p class="mt-1.5 text-sm text-muted">Páginas próprias da solução, no editor de blocos.</p>

    @can('update', $solution)
        {{-- Creates a page and goes STRAIGHT to it in the editor — the same
             endpoint (and the same redirect) as the "+" of the pages rail
             inside the editor, so there's only one way a page comes into
             existence. --}}
        <form id="solution-page-create-form" class="mt-4 flex items-center gap-2">
            @csrf
            <x-forms.input type="text" name="title" placeholder="Título da nova página"
                class="!h-9 min-w-0 flex-1 !text-sm" />
            <x-forms.button data-ak-ajax="solution-page-create-form"
                data-ak-action="{{ $createPageUrl }}"
                class="!h-9 !shrink-0 !px-3 !text-xs">
                <x-heroicon-o-plus class="size-4" /> Nova
            </x-forms.button>
        </form>
    @endcan

    @if ($pages->isNotEmpty())
        <div class="mt-4 flex flex-col gap-2">
            {{-- Subpages sit indented under their page, one step per level and
                 with the smaller turn-down icon — this card is where someone
                 first sees the shape of a solution's documentation. --}}
            @foreach ($pages as $page)
                <a href="{{ $page['url'] }}" @class([
                    'group flex items-center gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 text-sm no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent',
                    'ml-5' => ($page['depth'] ?? 0) === 1,
                    'ml-10' => ($page['depth'] ?? 0) === 2,
                    'ml-14' => ($page['depth'] ?? 0) === 3,
                    'ml-[72px]' => ($page['depth'] ?? 0) >= 4,
                ])>
                    @if (($page['depth'] ?? 0) > 0)
                        <x-heroicon-o-arrow-turn-down-right class="size-3.5 shrink-0 text-faint" />
                    @else
                        <x-heroicon-o-document-text class="size-4 shrink-0 text-faint" />
                    @endif
                    <span @class([
                        'min-w-0 flex-1 truncate',
                        'font-medium text-ink' => $page['hasContent'],
                        'italic text-muted' => ! $page['hasContent'],
                    ])>
                        {{ $page['title'] }}
                    </span>
                    @unless ($page['hasContent'])
                        <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-xs text-muted">Vazia</span>
                    @endunless
                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint" />
                </a>
            @endforeach
        </div>
    @else
        <div class="mt-4">
            <x-ui.empty-state illustration="docs" illustration-class="max-w-[104px]"
                title="Nenhuma documentação cadastrada"
                description="Crie a primeira página para descrever o que essa solução faz e como ela é operada." />
        </div>
    @endif
</div>
