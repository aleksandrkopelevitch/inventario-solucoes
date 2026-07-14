<div id="{{ $domId }}" @class(['flex flex-wrap items-center gap-2 transition-[margin] duration-200', 'hidden' => empty($chips)])>
    @foreach ($chips as $chip)
        <span data-ak-chip class="animate-chip-pop inline-flex items-center gap-1.5 rounded-full border border-accent-line bg-accent-soft py-1 pl-3 pr-1.5 text-xs font-semibold text-accent transition duration-150 ease-in">
            @if ($chip['label'])
                <span class="font-medium text-accent/70">{{ $chip['label'] }}:</span>
            @endif
            {{ $chip['value'] }}
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear="{{ json_encode(['formId' => 'people-filter-form', 'field' => $chip['field'], 'url' => route('people.index')]) }}"
                aria-label="Remover filtro {{ $chip['value'] }}"
                class="!rounded-full !p-0 !text-xs size-4 shrink-0 bg-accent/15 !text-accent hover:!bg-accent hover:!text-white">
                &times;
            </x-forms.button>
        </span>
    @endforeach

    @if (count($chips))
        <x-forms.button type="button" variant="ghost"
            data-ak-filters-clear-all="{{ json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')]) }}"
            class="!rounded-none !p-0 !text-xs underline decoration-line-2 underline-offset-2 hover:!bg-transparent">
            Limpar tudo
        </x-forms.button>
    @endif
</div>
