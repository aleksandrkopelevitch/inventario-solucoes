@props([
    'value',                         // the string somebody has to pass on
    'label',                         // PT-BR aria-label of the field
    'message' => 'Copiado.',         // PT-BR Toast once it is on the clipboard
    'copyLabel' => 'Copiar',         // PT-BR aria-label of the button
    'mono' => false,                 // tokens and endpoints read better monospaced
])

{{-- A read-only field and its copy button — copy-field.js does the copying,
     with its select-the-text fallback when the clipboard is refused. The same
     row was hand-written six times (a public link, a caderno's secret code, a
     person's access link, an MCP token, the MCP endpoint twice). --}}
<div {{ $attributes->class('flex items-center gap-2') }} data-ak-copy>
    <x-forms.input type="text" readonly data-ak-copy-field
        value="{{ $value }}"
        :class="$mono ? '!h-9 flex-1 font-mono !text-xs' : '!h-9 flex-1 !text-xs'"
        aria-label="{{ $label }}" />
    <x-forms.button type="button" variant="ghost" data-ak-copy-trigger
        data-ak-copy-message="{{ $message }}"
        class="!h-9 !w-9 shrink-0 !p-0" aria-label="{{ $copyLabel }}">
        <x-heroicon-o-clipboard-document class="size-5" />
    </x-forms.button>
</div>
