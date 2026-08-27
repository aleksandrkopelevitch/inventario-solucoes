{{-- The reading column: Editor.js (editable) or the rendered read-only
     Markdown. Extracted so the diagram's unified page can reuse it
     unchanged inside the Documentação tab (see documentation/edit.blade.php).
     Inherits the parent view's scope (canEdit, documentation, uploadUrl,
     renderedHtml, childPages). --}}
<div class="min-w-0 max-w-3xl flex-1">
    @if ($canEdit)
        {{-- Raw source Markdown; the editor builds the blocks from here.
             The <textarea> preserves the content with safe escaping. --}}
        <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

        {{-- Editor.js mount point (resources/js/modules/docs-editor.js).
             Block borders only appear on hover; the block menu opens with "/". --}}
        <div class="ak-docs-editor" data-ak-docs-editor
            data-config="{{ json_encode(['uploadUrl' => $uploadUrl]) }}"></div>

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
