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
                            Gerando com o especialista…
                        </span>

                        @isset($assistPanelUrl)
                            <x-forms.button type="button" variant="ghost" data-ak-docs-ai-trigger data-ak-panel-open data-ak-panel-url="{{ $assistPanelUrl }}"
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
                </div>
            </div>

            {{-- Scrollable content — fills the remaining height. A centered
                 reading column (GitBook/Medium/Substack) plus an "on this page"
                 headings navigator on the right (docs-toc.js), even though the
                 layout is fluid. --}}
            <div class="ak-docs-scroll min-h-0 flex-1 overflow-y-auto">
                <div class="mx-auto flex w-full max-w-[64rem] gap-8 px-6 py-8 md:px-10">
                    <div class="min-w-0 max-w-3xl flex-1">
                        @if ($canEdit)
                            {{-- Raw source Markdown; the editor builds the blocks from here.
                                 The <textarea> preserves the content with safe escaping. --}}
                            <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

                            {{-- Editor.js mount point (resources/js/modules/docs-editor.js).
                                 Block borders only appear on hover; the block menu opens with "/". --}}
                            <div class="ak-docs-editor" data-ak-docs-editor
                                data-config="{{ json_encode(['uploadUrl' => $uploadUrl]) }}"></div>

                            {{-- Resume marker: present when this user has an unresolved "Assiste IA"
                                 generation for this page/integration (e.g. they left while it was
                                 generating). docs-ai.js picks the flow back up on load — locks the
                                 editor and polls if still pending, or opens the review modal if it
                                 already finished. See AssistsDocumentation::aiResumeFor(). --}}
                            @if ($aiResume ?? null)
                                <div data-ak-docs-ai-resume
                                    data-poll-url="{{ $aiResume['pollUrl'] }}"
                                    data-consume-url="{{ $aiResume['consumeUrl'] }}"
                                    data-pending="{{ $aiResume['pending'] ? '1' : '0' }}"
                                    hidden></div>
                            @endif

                            <p class="mt-6 text-xs text-muted">
                                Dica: digite <kbd class="rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px]">/</kbd>
                                no início de um bloco para inserir títulos, listas, tabelas, hints, abas, imagens e arquivos —
                                ou use Markdown direto (<code>## </code>, <code>- </code>, <code>> </code>, <code>```</code>).
                            </p>

                            {{-- Review modal shell for "Assiste IA". docs-ai.js clones this into
                                 #main-modal after a draft is generated, injects the diff into
                                 [data-ak-docs-ai-review-body] and opens it — the draft only replaces
                                 the editor when "Aplicar rascunho" is clicked, never unseen. --}}
                            <template data-ak-docs-ai-review-template>
                                <div class="flex max-h-[82vh] flex-col">
                                    <div class="flex items-start justify-between gap-3 border-b border-line px-6 py-4">
                                        <div class="min-w-0">
                                            <h2 class="flex items-center gap-2 text-base font-bold text-ink">
                                                <x-heroicon-o-sparkles class="size-5 text-accent" />
                                                Revisar rascunho do especialista
                                            </h2>
                                            <p class="mt-0.5 text-xs text-muted">
                                                <span class="text-accent">Verde</span> = adicionado ·
                                                <span class="text-crit">vermelho</span> = removido. Nada muda até você aplicar.
                                            </p>
                                        </div>
                                        <x-forms.button type="button" variant="ghost" data-ak-docs-ai-discard
                                            class="!h-8 !w-8 shrink-0 !p-0" aria-label="Fechar">
                                            <x-heroicon-o-x-mark class="size-5" />
                                        </x-forms.button>
                                    </div>

                                    <div data-ak-docs-ai-review-warning
                                        class="mx-6 mt-4 hidden rounded-field border border-hot-line bg-hot-soft px-3 py-2 text-xs text-hot">
                                        Você editou a página enquanto o especialista gerava. Aplicar vai substituir o conteúdo atual do editor.
                                    </div>

                                    <div data-ak-docs-ai-review-body class="min-h-0 flex-1 overflow-y-auto px-6 py-4"></div>

                                    <div class="flex items-center justify-end gap-2 border-t border-line px-6 py-4">
                                        <x-forms.button type="button" variant="ghost" data-ak-docs-ai-discard>
                                            Descartar
                                        </x-forms.button>
                                        <x-forms.button type="button" data-ak-docs-ai-apply>
                                            <x-heroicon-o-check class="size-5" />
                                            Aplicar rascunho
                                        </x-forms.button>
                                    </div>
                                </div>
                            </template>
                        @else
                            @if (trim($renderedHtml) !== '')
                                {{-- Raw Markdown for the "Copiar Markdown" button (docs-copy.js) — there's no
                                     editor on this read-only screen, so this textarea is the source. --}}
                                <textarea data-ak-docs-markdown hidden>{{ $documentation }}</textarea>

                                <div class="html-content" data-ak-docs-content>
                                    {!! $renderedHtml !!}
                                </div>
                            @else
                                <p class="rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                                    Nenhuma documentação cadastrada ainda.
                                </p>
                            @endif
                        @endif
                    </div>

                    {{-- "Nesta página" headings navigator (H1/H2). Reads the live
                         Editor.js headings while editing, and the .html-content
                         permalinks when read-only. Built by docs-toc.js. --}}
                    <aside data-ak-docs-toc
                           class="hidden w-52 shrink-0 self-start xl:sticky xl:top-4 xl:block"></aside>
                </div>
            </div>
        </section>
    </div>
</x-layouts.layout>
