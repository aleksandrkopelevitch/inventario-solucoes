{{--
    The ↗ that opens the page a datum points at — a person's company, a linked
    system. Its own component because BOTH branches of x-ui.inline-edit render
    it: the editable one, where the value itself opens the editor, and the
    read-only one, where there is no editor at all.

    No color utility on purpose — it inherits the surrounding text color, so
    the same icon reads correctly on the header's gradient and on a white card.
--}}
@props([
    'href',
    // What is being opened — "empresa", "sistema". Accessible name only.
    'label' => null,
    // Extra attributes for the anchor, as an array (e.g. `target`/`rel` for a
    // link that leaves the app). A prop rather than the caller's own
    // `$attributes`, because the caller here is x-ui.inline-edit — echoing a
    // ComponentAttributeBag inside the attribute area of an x-component tag
    // silently breaks that tag's compilation (see the note in CLAUDE.md);
    // static attributes on a normal call site are unaffected and still land
    // via `$attributes` below.
    'extraAttributes' => [],
])

@php
    $title = 'Abrir' . ($label ? ' ' . $label : '');
@endphp

{{-- `data-ak-inline-edit-link` is not decoration: inside an editable
     x-ui.inline-edit this anchor sits INSIDE the read block, which is itself
     the editor's trigger — that hook is how inline-edit.js lets the navigation
     through instead of opening the editor over it. --}}
<a href="{{ $href }}" data-ak-inline-edit-link
   {{ new \Illuminate\View\ComponentAttributeBag($extraAttributes) }}
   {{ $attributes->class(['shrink-0 opacity-60 transition-opacity hover:opacity-100']) }}
   aria-label="{{ $title }}" title="{{ $title }}">
    <x-heroicon-o-arrow-top-right-on-square class="size-3.5" />
</a>
