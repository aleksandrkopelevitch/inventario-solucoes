{{-- The id sits on a bare wrapper, not on the card: a viewer with no notes to
     read gets no empty card, but a mutation still always has a slot to land in. --}}
<div id="{{ $domId }}">
    @if ($canEdit || filled($person->notes))
        <div class="animate-ak-rise mt-5 rounded-card border border-line bg-surface p-6 shadow-card" style="animation-delay: 90ms">
            <h2 class="font-display text-[18px] font-semibold text-ink">Anotações</h2>

            {{-- Written as Markdown, read as formatted text (x-ui.markdown);
                 the editor stays the plain textarea it always was. Line breaks
                 the author typed still survive — see the `soft_break` note in
                 App\Support\MarkdownText. Ctrl/Cmd+Enter saves here (plain
                 Enter is a newline the user meant to type). --}}
            <x-ui.inline-edit name="notes" type="textarea" :value="$person->notes" :rows="6"
                              :action="$canEdit ? route('people.field.update', $person) : null" :editable="$canEdit"
                              label="Anotações" empty="Adicionar anotações"
                              edit-class="w-full max-w-full" class="mt-2">
                <x-ui.markdown :text="$person->notes" class="text-sm leading-relaxed text-body" />
            </x-ui.inline-edit>
        </div>
    @endif
</div>
