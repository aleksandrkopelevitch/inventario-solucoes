<x-layouts.layout :title="$title" :fluid="true">
    <div class="flex min-h-0 flex-1">
        @isset($pagesNav)
            {{-- Collapsible pages rail (mirrors the flowSpec conversations rail).
                 The toggle button lives in the top bar below and carries
                 data-ak-toggle="docs-sidebar", animating this aside's width to 0.
                 The inner slot (pages-nav, fixed w-72) keeps content from
                 reflowing while it slides — and, being the updatable slot, it
                 can be swapped on a page move without losing the collapse state
                 held on this outer aside. --}}
            <aside id="docs-sidebar"
                   class="flex w-72 shrink-0 flex-col overflow-hidden border-r border-line bg-white transition-[width] duration-200 max-md:hidden">
                <x-documentation.pages-nav :pages="$pagesNav" :integrations="$integrationsNav ?? []" :create-page-url="$createPageUrl" />
            </aside>
        @endisset

        <section class="flex min-h-0 min-w-0 flex-1 flex-col">
            {{-- Top bar: collapse toggle + back link + title on the left, doc actions on the right. --}}
            <div class="flex items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    @isset($pagesNav)
                        <x-forms.button type="button" variant="ghost" class="!px-2 !py-1.5" title="Mostrar/ocultar páginas"
                            data-ak-toggle="docs-sidebar" data-ak-toggle-classes="!w-0 !border-r-0">
                            <span id="docs-sidebar-opened-state"><x-heroicon-o-chevron-double-left class="size-4" /></span>
                            <span id="docs-sidebar-closed-state" class="hidden whitespace-nowrap">
                                <x-heroicon-o-chevron-double-right class="inline size-4 align-text-bottom" /> Mostrar páginas
                            </span>
                        </x-forms.button>
                    @endisset

                    <x-forms.button :href="$backUrl" variant="ghost" class="!px-2 !py-1.5" title="Voltar" aria-label="Voltar">
                        <x-heroicon-o-arrow-left class="size-4" />
                    </x-forms.button>

                    <span class="truncate text-sm font-bold text-ink">{{ $backLabel }}</span>
                </div>

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

            {{-- Scrollable content — fills the remaining height, full width. --}}
            <div class="min-h-0 flex-1 overflow-y-auto">
                <div class="px-6 py-8 md:px-10">
                    @if ($canEdit)
                        {{-- Raw source Markdown; the editor builds the blocks from here.
                             The <textarea> preserves the content with safe escaping. --}}
                        <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

                        {{-- Editor.js mount point (resources/js/modules/docs-editor.js).
                             Block borders only appear on hover; the block menu opens with "/". --}}
                        <div class="ak-docs-editor" data-ak-docs-editor
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

                            <div class="html-content">
                                {!! $renderedHtml !!}
                            </div>
                        @else
                            <p class="rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                                Nenhuma documentação cadastrada ainda.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </section>
    </div>
</x-layouts.layout>
