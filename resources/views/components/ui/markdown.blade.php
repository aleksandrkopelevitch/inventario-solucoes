{{--
    Read mode of a short free-text field written in Markdown — a person's or
    company's notes, a solution's description, its support × operation note.
    The editor behind them is still a plain `<textarea>`
    (`x-ui.inline-edit type="textarea"`); this is only the reading side.

    Renders nothing at all when the field is blank, which is what keeps
    x-ui.inline-edit's own empty-slot placeholder ("Adicionar anotações") the
    thing a blank field shows.

    Typography stays with the CALLER: pass the same `text-sm …` classes the
    `<p>` here used to carry, and `.ak-rich-text` adds only spacing, emphasis
    and list marks on top (resources/css/components/rich-text.css).
--}}
@props([
    // The raw Markdown as the author typed it (a model column, never HTML).
    'text' => null,
])

@php
    $html = \App\Support\MarkdownText::toHtml($text);
@endphp

@if ($html !== '')
    {{-- Safe to echo unescaped: the converter strips raw HTML input and
         refuses unsafe links (see App\Support\MarkdownText). --}}
    <div {{ $attributes->class(['ak-rich-text']) }}>{!! $html !!}</div>
@endif
