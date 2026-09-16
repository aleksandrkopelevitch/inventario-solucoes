{{-- The documentation top bar's right-hand cluster.

     Two groups, in this order: what reports or commits the state of the page
     (the two "gerando…"/"Salvo" indicators and Salvar), then every icon button,
     after it. Salvar is the only thing here anyone presses on purpose, so it
     keeps the position the eye goes to and the icons stop breaking the line in
     the middle — "Abrir especialista" used to sit as a labelled pill BETWEEN
     the copy icon and Salvar, which put the primary action third in a row of
     six and left two icons stranded on the far side of it.

     "Abrir especialista" is an icon now, like the two beside it. It lost a
     label but joined a group, and the panel it opens announces itself in full
     the moment it does. The one that is GONE rather than demoted is "Soluções
     documentadas": what a caderno documents is a fact about the page, so it is
     stated in the right rail instead (x-notebooks.documented-systems), and the
     popover it used to open moved there with it.

     Inherits the parent view's scope as-is (canEdit, renderedHtml,
     chatPanelUrl, saveUrl, notebook, …). --}}
@if ($canEdit)
    {{-- Indicator while the chat's reply job runs (docs-chat.js reveals it). --}}
    <span data-ak-docs-chat-status class="hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
        <x-heroicon-o-sparkles class="size-4 animate-pulse" />
        Gerando com o especialista…
    </span>

    <span data-ak-docs-status class="text-xs text-muted" aria-live="polite"></span>
    <x-forms.button type="button" data-ak-docs-save data-action="{{ $saveUrl }}" class="!h-9 !rounded-full !px-4 !text-sm">
        Salvar
    </x-forms.button>
@endif

{{-- The icon group. `gap-0.5` rather than the bar's own `gap-3`: these read as
     one set of related affordances, not as three separate decisions. --}}
<div class="flex shrink-0 items-center gap-0.5">
    @if ($canEdit || trim($renderedHtml) !== '')
        <x-forms.button type="button" variant="ghost" data-ak-docs-copy
            class="!h-9 !w-9 !p-0" aria-label="Copiar Markdown" title="Copiar Markdown">
            <x-heroicon-o-clipboard-document class="size-5" />
        </x-forms.button>
    @endif

    {{-- "Desenhar esta página" — one menu, five pictures.

         The first item is the only one that produces something EDITABLE: a
         Diagram in its own module, drawn on the F3 canvas, feeding the
         ecosystem map like any other. The four below it are rendered artifacts
         (Archify), and they answer the questions a topology cannot — in what
         order the calls happen, where the data comes to rest, what the states
         of a run are, who approves what. They are not chains and never become
         one, which is why they are stored as media on the page and read in a
         tab of their own.

         Two different abilities, deliberately: drawing a chain writes a row in
         another module (`create` on Diagram), while an artifact belongs to this
         page (`update` on it, which is what `$canEdit` already answered).

         Each item is its own <form class="contents">: ajax-post.js builds a
         FormData from the form a button names, and there is no form in this bar
         to borrow. No `type="button"` on any of them — a button carrying
         `data-ak-ajax` must stay a submit, or Enter silently stops working
         (AGENTS.md). --}}
    {{-- The trigger appears only when the menu would have something in it.
         Without this a Viewer got the button and an EMPTY popover — an
         affordance for two things they may not do. The two conditions are the
         two halves of the menu, in the same order. --}}
    @isset($diagramDraftUrl)
    @if ($canEdit || (auth()->user()?->can('create', App\Models\Diagram::class) ?? false))
        <div class="relative">
            <x-forms.button type="button" variant="ghost" data-ak-toggle="docs-draw-menu"
                data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                class="!h-9 !w-9 !p-0" aria-label="Desenhar esta página" title="Desenhar esta página">
                <x-heroicon-o-rectangle-group class="size-5" />
            </x-forms.button>

            <div id="docs-draw-menu" class="hidden absolute right-0 top-full z-20 mt-1.5 w-72 rounded-field border border-line bg-surface p-1.5 shadow-xl">
                @can('create', App\Models\Diagram::class)
                    <form id="docs-diagram-form" class="contents">
                        <x-forms.button variant="ghost" data-ak-ajax="docs-diagram-form" data-ak-action="{{ $diagramDraftUrl }}"
                            class="!h-auto !w-full !justify-start !gap-2.5 !rounded-md !px-2 !py-1.5 !text-left">
                            <x-heroicon-o-share class="size-4 shrink-0 text-muted" />
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-semibold text-ink">Fluxo, no canvas</span>
                                <span class="block truncate text-[11px] text-muted">Um diagrama editável, com os sistemas do catálogo</span>
                            </span>
                        </x-forms.button>
                    </form>

                    <span class="my-1 block h-px bg-line"></span>
                @endcan

                @isset($modelUrls)
                    @foreach ($modelUrls as $entry)
                        <form id="docs-model-form-{{ $entry['model']->value }}" class="contents">
                            <x-forms.button variant="ghost"
                                data-ak-ajax="docs-model-form-{{ $entry['model']->value }}"
                                data-ak-action="{{ $entry['url'] }}"
                                class="!h-auto !w-full !justify-start !gap-2.5 !rounded-md !px-2 !py-1.5 !text-left">
                                <x-dynamic-component :component="'heroicon-o-' . $entry['model']->icon()" class="size-4 shrink-0 text-muted" />
                                <span class="min-w-0">
                                    <span class="block truncate text-xs font-semibold text-ink">{{ $entry['model']->label() }}</span>
                                    <span class="block truncate text-[11px] text-muted">{{ $entry['model']->hint() }}</span>
                                </span>
                            </x-forms.button>
                        </form>
                    @endforeach
                @endisset
            </div>
        </div>
    @endif
    @endisset

    @if ($canEdit)
        {{-- `data-ak-panel-dock` anchors the panel as `#docs-shell`'s right COLUMN
             (2026-08-29) — talking about the documentation while it disappeared
             behind the panel was the problem. `data-ak-panel-size` stays on
             purpose: it is what applies below 1024px, where side-panel.js refuses
             the dock and falls back to the floating panel. --}}
        @isset($chatPanelUrl)
            <x-forms.button type="button" variant="ghost" data-ak-docs-chat-trigger data-ak-panel-open
                data-ak-panel-url="{{ $chatPanelUrl }}" data-ak-panel-dock="docs-shell" data-ak-panel-size="large"
                class="!h-9 !w-9 !p-0" aria-label="Abrir especialista" title="Abrir especialista">
                <x-heroicon-o-sparkles class="size-5" />
            </x-forms.button>
        @endisset
    @endif

    {{-- The caderno's public magic link. Belongs to the CADERNO rather than to
         this page, which is why it is reachable from whichever page you happen
         to be reading. `@isset` guards a caller that renders this cluster
         without one. --}}
    @isset($notebook)
        {{-- `administer`, not `update`: this dropdown publishes the caderno AND
             shows its secret code, and an EDITOR can write every page in it
             without being allowed to do either (see NotebookPolicy). It read
             `update` while that ability meant "admin". --}}
        @can('administer', $notebook)
            <div class="relative">
                <x-forms.button type="button" variant="ghost" data-ak-toggle="docs-share-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                    class="!h-9 !w-9 !p-0" aria-label="Compartilhar caderno" title="Compartilhar caderno">
                    <x-heroicon-o-share class="size-5" />
                </x-forms.button>
                <div id="docs-share-dropdown" class="hidden absolute right-0 top-full z-20 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
                    <x-notebooks.share-panel :notebook="$notebook" />
                </div>
            </div>
        @endcan
    @endisset
</div>
