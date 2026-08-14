{{--
    The confirm/cancel pair of an `x-ui.inline-edit` editor. Internal to that
    component — it exists only because the pair is rendered in two positions
    (beside a one-line field, under a tall or multi-field one) and the two must
    never drift apart. Don't use it directly.

    Icon-only on purpose: this is a punctual edit, not a form, and a pair of
    labelled buttons would outweigh the datum being edited. The keyboard does
    the same job (Enter/Esc), which the tooltips say out loud.
--}}
@props([
    // Rendered under the field instead of beside it — needs the top margin.
    'stacked' => false,
])

<div @class(['flex shrink-0 items-center gap-0.5', 'mt-1.5' => $stacked])>
    {{-- Confirm carries the soft accent fill: of the two it's the one being
         aimed at, and a pair of identical grey circles makes the user read the
         icons before clicking. --}}
    <x-forms.button type="button" variant="ghost" data-ak-inline-edit-confirm
                    class="!size-7 !rounded-full !bg-accent-soft !p-0 !text-accent !ring-1 !ring-accent-line hover:!bg-accent-line"
                    aria-label="Confirmar" title="Confirmar (Enter)">
        <x-heroicon-o-check class="size-4" />
    </x-forms.button>

    <x-forms.button type="button" variant="ghost" data-ak-inline-edit-cancel
                    class="!size-7 !rounded-full !p-0 !text-faint hover:!bg-raised hover:!text-ink"
                    aria-label="Cancelar" title="Cancelar (Esc)">
        <x-heroicon-o-x-mark class="size-4" />
    </x-forms.button>
</div>
