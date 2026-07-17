<x-layouts.layout :title="$title" :breadcrumbs="$breadcrumbs ?? null">
    <div @class([
        'mx-auto flex items-stretch rounded-card border border-line bg-white',
        //'max-w-3xl' => ! isset($pagesNav),
        //'max-w-5xl' => isset($pagesNav),
    ])>
        @isset($pagesNav)
            <x-documentation.pages-nav :pages="$pagesNav" :integrations="$integrationsNav ?? []" :create-page-url="$createPageUrl" />
        @endisset

        <div class="min-w-0 flex-1">
            {{-- Top bar: back + save (sticky at the top on scroll, right below the global header) --}}
            <div class="sticky top-14 z-10 flex items-center justify-between gap-3 rounded-t-card border-b border-line bg-white px-6 py-3">
                <span class="text-sm text-ink font-bold">
                    {{ $backLabel }}
                </span>

                <div class="flex items-center gap-3">
                    @if ($canEdit || trim($renderedHtml) !== '')
                        <x-forms.button type="button" variant="ghost" data-ak-docs-copy
                            class="!h-9 !w-9 !p-0" aria-label="Copiar Markdown" title="Copiar Markdown">
                            <x-heroicon-o-clipboard-document class="size-5" />
                        </x-forms.button>
                    @endif

                    @if ($canEdit)
                        {{-- "Assiste IA" indicator while the job generates (docs-ai.js reveals it). --}}
                        <span data-ak-docs-ai-status class="hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
                            <x-heroicon-o-sparkles class="size-4 animate-pulse" />
                            Gerando com IA…
                        </span>

                        @isset($assistPanelUrl)
                            <x-forms.button type="button" variant="ghost" data-ak-panel-open data-ak-panel-url="{{ $assistPanelUrl }}"
                                class="!h-9 !px-3 !text-sm">
                                <x-heroicon-o-sparkles class="size-4" />
                                <span>Assiste IA</span>
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
                </div>
            </div>

            <div class="px-8">
                @if ($canEdit)
                    {{-- Raw source Markdown; the editor builds the blocks from here.
                         The <textarea> preserves the content with safe escaping. --}}
                    <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

                    {{-- Editor.js mount point (resources/js/modules/docs-editor.js).
                         Block borders only appear on hover; the block menu opens with "/". --}}
                    <div class="mt-6 ak-docs-editor" data-ak-docs-editor
                        data-config="{{ json_encode(['uploadUrl' => $uploadUrl]) }}"></div>

                    <p class="mt-6 text-xs text-muted">
                        Dica: digite <kbd class="rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px]">/</kbd>
                        no início de um bloco para inserir títulos, listas, tabelas, hints, abas, imagens e arquivos —
                        ou use Markdown direto (<code>## </code>, <code>- </code>, <code>> </code>, <code>```</code>).
                    </p>
                @else
                    @if (trim($renderedHtml) !== '')
                        {{-- Raw Markdown for the "Copiar Markdown" button (docs-copy.js) — there's no
                             editor on this read-only screen, so this textarea is the source. --}}
                        <textarea data-ak-docs-markdown hidden>{{ $documentation }}</textarea>

                        <div class="html-content mt-6">
                            {!! $renderedHtml !!}
                        </div>
                    @else
                        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                            Nenhuma documentação cadastrada ainda.
                        </p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-layouts.layout>
