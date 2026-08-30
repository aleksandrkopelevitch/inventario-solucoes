{{--
    The confirm/cancel pair of an `x-ui.inline-edit` editor. Internal to that
    component — don't use it directly.

    It is two bare glyphs in the editor's bottom-right corner, absolutely
    positioned so they cost the field no width at all — ONE placement for every
    layout, where there used to be two (beside a one-line field, under a tall
    one) that had to be kept from drifting apart. They used to be a pair of
    28px pills — one with an accent fill and a ring — sitting in the flex row
    beside the input, which took about 60px off every field being edited and
    made a punctual edit look like a form with a submit button.

    Small is the right size for them because they are the SECOND way to do this:
    Enter/Esc (Ctrl+Enter in a textarea) is the first, and the tooltips say so.
    The pair is here to be found, not to be aimed at — so it holds a real target
    where there is no hover to reveal anything (`pointer-coarse`), the same deal
    the pencil in read mode makes.
--}}
<div class="absolute bottom-0.5 right-0 flex items-center gap-1">
    {{-- Confirm keeps the accent colour: of the two it is the one being aimed
         at, and with the fills gone colour is all that separates them. --}}
    <x-forms.button type="button" variant="ghost" data-ak-inline-edit-confirm
                    class="!size-4 !rounded !p-0 !text-accent hover:!bg-transparent hover:!text-accent-press
                           pointer-coarse:!size-7"
                    aria-label="Confirmar" title="Confirmar (Enter)">
        <x-heroicon-o-check class="size-3.5 pointer-coarse:size-5" />
    </x-forms.button>

    <x-forms.button type="button" variant="ghost" data-ak-inline-edit-cancel
                    class="!size-4 !rounded !p-0 !text-faint hover:!bg-transparent hover:!text-ink
                           pointer-coarse:!size-7"
                    aria-label="Cancelar" title="Cancelar (Esc)">
        <x-heroicon-o-x-mark class="size-3.5 pointer-coarse:size-5" />
    </x-forms.button>
</div>
