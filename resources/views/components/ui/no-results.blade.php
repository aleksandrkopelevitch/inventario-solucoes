@props([
    'title',      // PT-BR: "Nenhuma empresa encontrada"
    'formId',     // the list's filter form, whose `filter[...]` fields "Limpar filtros" empties
    'url',        // the index the cleared form is re-submitted to
])

{{-- A list whose filters left nothing to show — not an empty list. The
     difference matters: "nothing yet" invites creating the first record
     (x-ui.empty-state, with its illustration), while this one says the records
     exist and offers the way back to them. Companies, people and solutions
     carried the same twelve lines; diagrams and cadernos improvised their own
     (one a bare sentence, one an illustration with no way out). --}}
<div {{ $attributes->class('animate-ak-pop flex flex-col items-center rounded-card border border-dashed border-line-2 bg-surface p-12 text-center text-sm text-muted') }}>
    <span class="mb-4 flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-lime-soft to-accent-soft text-accent shadow-sm ring-1 ring-accent-line">
        <x-heroicon-o-magnifying-glass class="size-6" />
    </span>
    <p class="mb-1 font-semibold text-ink">{{ $title }}</p>
    <p class="mb-4 max-w-xs">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
    <x-forms.button type="button" variant="ghost"
        data-ak-filters-clear-all="{{ json_encode(['formId' => $formId, 'url' => $url]) }}"
        class="border border-line-2 !text-accent hover:!bg-accent-soft">
        Limpar filtros
    </x-forms.button>
</div>
