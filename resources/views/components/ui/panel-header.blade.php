@props([
    'title',   // PT-BR: "Nova empresa", "Editar pessoa"
])

{{-- The top of a side panel (#side-panel): its title and the × that closes
     it (`data-ak-panel-close`, side-panel.js). The catalog's create/edit
     panels — companies, people, solutions — each wrote it by hand. --}}
<div {{ $attributes->class('flex items-center justify-between border-b border-line px-5 py-4') }}>
    <h2 class="font-display text-lg font-semibold text-ink">{{ $title }}</h2>
    <x-forms.button type="button" variant="ghost" data-ak-panel-close
        class="!size-8 !p-0 !text-xl !leading-none !font-normal !text-faint hover:!text-ink" aria-label="Fechar">&times;</x-forms.button>
</div>
