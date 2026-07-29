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
                    @isset($integration)
                        {{-- Documentação/Diagrama tabs — the integration's unified
                             page. Segmented control (existing tabs.js module):
                             switching swaps the two panels below without leaving
                             the page or losing the pages-nav sidebar. The
                             doc-specific actions (copy/assist/save) moved out of
                             this persistent bar and into the Documentação panel
                             itself (see below) — the Diagrama panel has its own
                             Salvar (chain layout) inside the canvas, so showing
                             both at once here would read as two different
                             "Salvar" buttons stacked on top of each other. --}}
                        <div class="flex items-center gap-1 rounded-field bg-raised p-1" role="tablist" aria-label="Documentação ou diagrama">
                            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="true" tabindex="0"
                                data-ak-tabs="{{ json_encode(['targetId' => 'integration-tab-docs', 'targetContainerId' => 'integration-tab-panels', 'activeClasses' => ['!bg-surface', '!text-ink', '!shadow-sm'], 'inactiveClasses' => ['!bg-transparent', '!text-muted', '!shadow-none'], 'selectedOnInit' => true]) }}"
                                class="!h-8 !gap-1.5 !rounded !bg-surface !px-3 !text-xs !font-semibold !text-ink !shadow-sm">
                                <x-heroicon-o-document-text class="size-4" /> Documentação
                            </x-forms.button>
                            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="false" tabindex="-1"
                                data-ak-tabs="{{ json_encode(['targetId' => 'integration-tab-diagram', 'targetContainerId' => 'integration-tab-panels', 'activeClasses' => ['!bg-surface', '!text-ink', '!shadow-sm'], 'inactiveClasses' => ['!bg-transparent', '!text-muted', '!shadow-none']]) }}"
                                class="!h-8 !gap-1.5 !rounded !bg-transparent !px-3 !text-xs !font-semibold !text-muted !shadow-none">
                                <x-heroicon-o-share class="size-4" /> Diagrama
                            </x-forms.button>
                        </div>
                    @else
                        @include('documentation.partials._actions')
                    @endisset
                </div>
            </div>

            @isset($integration)
                {{-- Two tab panels sharing one container id — tabs.js swaps
                     which one is visible; both stay mounted (the canvas draws
                     once on load, it shouldn't remount on every tab switch). --}}
                <div id="integration-tab-panels" class="flex min-h-0 flex-1 flex-col">
                    <div id="integration-tab-docs" class="ak-docs-scroll flex min-h-0 flex-1 flex-col overflow-y-auto">
                        {{-- Sticky: reads like the page's own top bar even though
                             it now lives inside this scrollable tab panel — moved
                             here (from the persistent top bar above) so it's
                             swapped out for the canvas's own actions the moment
                             the Diagrama tab is active. --}}
                        <div class="sticky top-0 z-10 flex items-center justify-end gap-3 border-b border-line bg-white px-3 py-2">
                            @include('documentation.partials._actions')
                        </div>
                        <div class="mx-auto flex w-full max-w-[64rem] gap-8 px-6 py-8 md:px-10">
                            @include('documentation.partials._reader')

                            {{-- "Nesta página" headings navigator (H1/H2). Reads the live
                                 Editor.js headings while editing, and the .html-content
                                 permalinks when read-only. Built by docs-toc.js. --}}
                            <aside data-ak-docs-toc
                                   class="hidden w-52 shrink-0 self-start xl:sticky xl:top-4 xl:block"></aside>
                        </div>
                    </div>

                    {{-- The F3 chain canvas (Solutions\IntegrationWorkspace) — hidden
                         until this tab is selected, but mounted immediately (not
                         lazily), same as the Documentação panel above. --}}
                    <div id="integration-tab-diagram" class="hidden flex min-h-0 flex-1 flex-col">
                        <x-solutions.integration-workspace :solution="$solution" :integration="$integration" />
                    </div>
                </div>
            @else
                {{-- Scrollable content — fills the remaining height. A centered
                     reading column (GitBook/Medium/Substack) plus an "on this page"
                     headings navigator on the right (docs-toc.js), even though the
                     layout is fluid. --}}
                <div class="ak-docs-scroll min-h-0 flex-1 overflow-y-auto">
                    <div class="mx-auto flex w-full max-w-[64rem] gap-8 px-6 py-8 md:px-10">
                        @include('documentation.partials._reader')

                        {{-- "Nesta página" headings navigator (H1/H2). Reads the live
                             Editor.js headings while editing, and the .html-content
                             permalinks when read-only. Built by docs-toc.js. --}}
                        <aside data-ak-docs-toc
                               class="hidden w-52 shrink-0 self-start xl:sticky xl:top-4 xl:block"></aside>
                    </div>
                </div>
            @endisset
        </section>
    </div>
</x-layouts.layout>
