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
        @can('update', $notebook)
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
