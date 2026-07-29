{{-- Documentation-specific actions (copy Markdown / Especialista / Salvar /
     share). Extracted so the integration's unified page (see
     documentation/edit.blade.php) can render this same cluster inside the
     Documentação tab instead of the persistent top bar — it has nothing to
     do with the Diagrama tab, which has its own Salvar (chain layout) inside
     the canvas itself. Inherits the parent view's scope as-is (canEdit,
     renderedHtml, chatPanelUrl, saveUrl, coverageSolution, …). --}}
@if ($canEdit || trim($renderedHtml) !== '')
    <x-forms.button type="button" variant="ghost" data-ak-docs-copy
        class="!h-9 !w-9 !p-0" aria-label="Copiar Markdown" title="Copiar Markdown">
        <x-heroicon-o-clipboard-document class="size-5" />
    </x-forms.button>
@endif

@if ($canEdit)
    {{-- Indicator while the chat's reply job runs (docs-chat.js reveals it). --}}
    <span data-ak-docs-chat-status class="hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
        <x-heroicon-o-sparkles class="size-4 animate-pulse" />
        Gerando com o especialista…
    </span>

    @isset($chatPanelUrl)
        <x-forms.button type="button" variant="ghost" data-ak-docs-chat-trigger data-ak-panel-open data-ak-panel-url="{{ $chatPanelUrl }}" data-ak-panel-size="large"
            class="!h-9 !px-3 !text-sm">
            <x-heroicon-o-sparkles class="size-4" />
            <span>Abrir especialista</span>
        </x-forms.button>
    @endisset

    <span data-ak-docs-status class="text-xs text-muted" aria-live="polite"></span>
    <x-forms.button type="button" data-ak-docs-save data-action="{{ $saveUrl }}" class="!h-9 !px-4 !text-sm">
        Salvar
    </x-forms.button>
@endif

{{-- Share (public link) — only on the Solution's own doc
     ($coverageSolution only comes from SolutionDocumentationController)
     and only for whoever can edit it. --}}
@isset($coverageSolution)
    @can('update', $coverageSolution)
        <div class="relative">
            <x-forms.button type="button" variant="ghost" data-ak-toggle="docs-share-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                class="!h-9 !w-9 !p-0" aria-label="Compartilhar documentação">
                <x-heroicon-o-share class="size-5" />
            </x-forms.button>
            <div id="docs-share-dropdown" class="hidden absolute right-0 top-full z-20 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
                <x-solutions.share-panel :solution="$coverageSolution" />
            </div>
        </div>
    @endcan
@endisset
