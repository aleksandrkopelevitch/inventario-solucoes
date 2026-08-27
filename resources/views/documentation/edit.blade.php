<x-layouts.layout :title="$title" :fluid="true">
    <div class="flex min-h-0 flex-1">
        @isset($pagesNav)
            {{-- Collapsible pages rail (mirrors the flowSpec conversations rail).
                 The toggle button lives in the top bar below and carries
                 data-ak-toggle="docs-sidebar", animating this aside's width to 0.
                 The inner slot (pages-nav, fixed w-72) keeps content from
                 reflowing while it slides — and, being the updatable slot, it
                 can be swapped on a page move without losing the collapse state
                 held on this outer aside.

                 Two different collapse mechanics, one shared toggle target —
                 and one shared button, which toggles BOTH class sets at once
                 (each is inert on the other's side of the breakpoint):
                 - md+ : in-flow rail, collapsed by animating width to 0
                         (`md:!w-0`, so it can't also fire on mobile).
                 - < md: off-canvas overlay slid out of view by default
                         (`-translate-x-full`), since an in-flow 18rem rail
                         would leave no room for the editor itself. This used
                         to be a plain `max-md:hidden`, which `display:none`d
                         the rail no matter what the width toggle did — so the
                         button was visible and clickable on a phone but could
                         never reveal anything, leaving no way at all to move
                         between pages from within the editor.

                 Both of those class names carry a Tailwind variant, i.e. a
                 colon — which `toggle.js` used to read as its own
                 `class:delay-in-ms` syntax and split on, toggling a class
                 literally named `md`. That's why this rail didn't collapse at
                 all until 2026-08-15; the fix lives in toggle.js. --}}
            <aside id="docs-sidebar"
                   class="ak-sidebar flex w-72 shrink-0 flex-col overflow-hidden border-r border-line bg-white transition-[width] duration-200
                          max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-40 max-md:-translate-x-full max-md:shadow-xl max-md:transition-transform">
                {{-- Mobile-only dismiss: the overlay covers the top bar's own
                     toggle button, so without this there'd be nothing left to
                     tap to close it. Same toggle target/classes as the button
                     that opens it. --}}
                <div class="flex justify-end border-b border-line p-2 md:hidden">
                    <x-forms.button type="button" variant="ghost" class="!px-2 !py-1.5" title="Fechar páginas" aria-label="Fechar páginas"
                        data-ak-toggle="docs-sidebar" data-ak-toggle-classes="md:!w-0 md:!border-r-0 max-md:!translate-x-0">
                        <x-heroicon-o-x-mark class="size-4" />
                    </x-forms.button>
                </div>
                <x-documentation.pages-nav :pages="$pagesNav"
                                           :create-page-url="$createPageUrl"
                                           :title="$notebookLabel"
                                           :rename-url="$notebookRenameUrl ?? null"
                                           :can-edit="$notebookEditable ?? false" />
            </aside>
        @endisset

        <section class="flex min-h-0 min-w-0 flex-1 flex-col">
            {{-- Top bar: collapse toggle + "Solução › Página" crumb on the left, doc actions on the right. --}}
            <div class="flex items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    @isset($pagesNav)
                        {{-- ONE button for both collapse mechanics: the `md:`
                             classes are inert below the breakpoint and the
                             `max-md:` one above it, so toggling all three
                             together can never desync the two states (which is
                             what a pair of buttons, one per breakpoint, used to
                             risk). The icon doesn't flip — the crumb appearing
                             beside it is the state feedback. --}}
                        <x-forms.button type="button" variant="ghost" class="!px-2 !py-1.5 shrink-0" title="Mostrar/ocultar páginas" aria-label="Mostrar/ocultar páginas"
                            data-ak-toggle="docs-sidebar" data-ak-toggle-classes="md:!w-0 md:!border-r-0 max-md:!translate-x-0">
                            <x-heroicon-o-bars-3 class="size-4" />
                        </x-forms.button>
                    @endisset

                    {{-- Which solution/group this page belongs to. Two copies,
                         because only one of them can be the toggle's
                         `-closed-state` element:
                         - below md the rail is an off-canvas overlay that
                           covers this bar, so nothing here tracks its state —
                           this copy is simply always on. It bows out under
                           `sm`, where the actions on the right leave it about
                           40px and a truncated name is worse than no name (the
                           rail is one tap away and its header says it in full).
                         - from md up the rail is in flow and its own header
                           already names the solution, so the crumb only appears
                           once that rail is collapsed — toggle.js flips
                           `docs-sidebar-closed-state` along with the rail. Both
                           of its classes are `display:none`, so the `hidden` it
                           toggles can't lose a specificity race below md. --}}
                    <x-documentation.crumb :label="$notebookLabel" :url="$notebookUrl"
                                           class="{{ isset($pagesNav) ? 'max-sm:hidden md:hidden' : '' }}" />
                    @isset($pagesNav)
                        <x-documentation.crumb :label="$notebookLabel" :url="$notebookUrl"
                                               id="docs-sidebar-closed-state" class="hidden max-md:hidden" />
                    @endisset

                    {{-- Editable in place. `$titlePage` is only set by the
                         notebook editor; the diagram's unified page renders the
                         same bar without one. --}}
                    @isset($titlePage)
                        <x-documentation.page-title :notebook="$notebook" :page="$titlePage" />
                    @else
                        <span class="truncate text-sm font-bold text-ink">{{ $pageLabel }}</span>
                    @endisset
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    @include('documentation.partials._actions')
                </div>
            </div>

            {{-- Scrollable content — fills the remaining height. A centered
                 reading column (GitBook/Medium/Substack) plus an "on this page"
                 headings navigator on the right (docs-toc.js), even though the
                 layout is fluid.

                 It used to be one of TWO branches: a page with a linked diagram
                 became a Documentação/Diagrama tab pair with the F3 canvas
                 mounted beside the text. That link is gone — a page CITES
                 drawings in its content now (a `diagram` block), which is a
                 reference rather than a second screen — so there is one shape
                 again. --}}
            <div class="ak-docs-scroll min-h-0 flex-1 overflow-y-auto bg-white">
                <div class="mx-auto flex w-full max-w-[64rem] gap-8 px-6 py-8 md:px-10">
                    @include('documentation.partials._reader')

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
