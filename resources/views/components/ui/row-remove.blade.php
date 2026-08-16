{{--
    The ✕ that unlinks one row of a relation card — a person from a solution, a
    system from a person, a contact from the strip. Five near-identical copies
    of "hidden form + ghost button" before this component existed; the reason it
    exists as one is the rule below, which has to hold everywhere or nowhere.

    **It steps aside while that row is being edited.** A row carries two ✕ the
    moment an `x-ui.inline-edit` inside it opens: the editor's own cancel
    (`x-ui.inline-edit-actions`) and this one — identical glyphs, side by side,
    one abandoning a half-typed value and the other severing a link for good.
    `group-has-[…]/row:invisible` reads the state the editor already publishes
    (`inline-edit.js` drops `hidden` from `[data-ak-inline-edit-form]` when it
    opens), so nothing new has to be kept in sync.

    Why `invisible` and not `hidden`:
    - `visibility: hidden` keeps the row's height and the button's slot, so
      opening an editor doesn't reflow the line — the whole point of edit-in-place.
    - it's a `visibility` utility, so it never fights `x-forms.button`'s own
      `inline-flex`, and it beats the `opacity-100` this button gets on hover
      without an `!` (different property, no tie to break).
    - it takes the button out of hit-testing AND out of the tab order, so Tab
      from the open field reaches confirm/cancel, never the destructive one.

    The enclosing row must be `group/row` for that to fire. A row with no editor
    in it (the two Companies cards) simply never matches — same component, rule
    idles.
--}}
@props([
    // DOM id shared by the hidden form and the button that posts it.
    'id',
    // DELETE endpoint.
    'action',
    // `window.confirm` text — this is a destructive action, it always asks.
    'confirm',
    // Accessible name: "Desvincular Ana Silva", "Remover contato".
    'label',
    // 'default' sits on a list row; 'small' sits inside the contacts strip's
    // uppercase label line, where a 24px target would outweigh the label.
    'size' => 'default',
])

@php
    $small = $size === 'small';

    // Built here, as a plain string, rather than echoed as a
    // ComponentAttributeBag in the attribute area of the <x-forms.button> tag
    // below — that silently breaks the tag's compilation (see CLAUDE.md).
    $classes = implode(' ', [
        $small ? '!size-5' : '!size-6',
        '!rounded-full !p-0 shrink-0 text-faint transition-opacity',
        'opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100',
        'group-has-[[data-ak-inline-edit-form]:not(.hidden)]/row:invisible',
        'hover:!bg-crit-soft hover:!text-crit',
    ]);
@endphp

<form id="{{ $id }}" class="hidden">
    @csrf
    @method('DELETE')
</form>

<x-forms.button type="button" variant="ghost" :class="$classes"
                data-ak-ajax="{{ $id }}" data-ak-action="{{ $action }}" data-ak-confirm="{{ $confirm }}"
                aria-label="{{ $label }}" title="{{ $label }}">
    <x-heroicon-o-x-mark @class(['size-3' => $small, 'size-3.5' => ! $small]) />
</x-forms.button>
