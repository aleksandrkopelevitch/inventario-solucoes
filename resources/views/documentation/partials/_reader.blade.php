{{-- The reading column: Editor.js (editable) or the rendered read-only
     Markdown. Extracted so the diagram's unified page can reuse it
     unchanged inside the Documentação tab (see documentation/edit.blade.php).
     Inherits the parent view's scope (canEdit, documentation, uploadUrl,
     renderedHtml, childPages). --}}
{{-- `data-ak-secret-*` sits on the COLUMN rather than on the rendered HTML
     because a protected value has two homes on this screen: a lock in the
     read-only render, and a `[[SECRET-n]]` marker inside Editor.js. docs-secret.js
     finds whichever was clicked with `closest('[data-ak-secret-url]')`, so both
     branches below are covered by one declaration.

     `__INDEX__` in the URL is the lock's ordinal, substituted client-side.
     Absent (with the whole feature inert) on a screen with no page behind it. --}}
<div class="min-w-0 max-w-3xl flex-1"
    @isset($secretRevealUrl) data-ak-secret-url="{{ $secretRevealUrl }}" @endisset
    @isset($secretScope) data-ak-secret-scope="{{ $secretScope }}" @endisset
    @if ($secretsUnlocked ?? false) data-ak-secret-unlocked="1" @endif>
    @if ($canEdit)
        {{-- Raw source Markdown; the editor builds the blocks from here.
             The <textarea> preserves the content with safe escaping. --}}
        <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

        {{-- Editor.js mount point (resources/js/modules/docs-editor.js).
             Block borders only appear on hover; the block menu opens with "/".

             `linkTargetsUrl`/`pageSlug` drive the Link tool's picker
             (docs-tools/link.js): what this caderno's pages and headings are,
             and which of them is the page being written — a heading of the
             CURRENT page is linked as a plain `#anchor`, everything else as
             `page:{slug}#anchor`. Both are `?? null` for a caller that renders
             this column without a caderno behind it; the tool then falls back to
             plain URLs, which is exactly the built-in behaviour. --}}
        <div class="ak-docs-editor" data-ak-docs-editor
            data-config="{{ json_encode([
                'uploadUrl'      => $uploadUrl,
                'catalogUrl'     => route('diagrams.catalog'),
                'linkTargetsUrl' => isset($notebook) ? route('notebooks.link-targets', $notebook) : null,
                'pageSlug'       => $titlePage->slug ?? null,
            ]) }}"></div>

        {{-- Resume marker: present when this user has a Documentation Assistant
             chat still generating a reply for this page/diagram (e.g. they
             closed the panel or navigated away). docs-chat.js locks the editor
             and resumes polling on load — see AssistsDocumentation::chatResumeFor(). --}}
        @if ($chatResume ?? null)
            <div data-ak-docs-chat-resume data-status-url="{{ $chatResume['statusUrl'] }}" hidden></div>
        @endif

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

            <div class="html-content" data-ak-docs-content>
                {!! $renderedHtml !!}
            </div>
        @elseif (empty($childPages ?? []))
            {{-- Only when there is nothing at all. A page with no text but WITH
                 sub-pages is a section heading, not a gap — GitBook's own
                 corpus is full of them — so the cards below are the content and
                 saying "nothing here yet" over them would be wrong. --}}
            <p class="rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                Nenhuma documentação cadastrada ainda.
            </p>
        @endif
    @endif

    {{-- Outside the @if: navigation belongs to the page whether or not somebody
         is editing its text, and whether or not there is any text to edit. --}}
    <x-documentation.child-pages :pages="$childPages ?? []" />
</div>
