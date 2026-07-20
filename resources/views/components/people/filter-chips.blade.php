<div id="{{ $domId }}" @class(['flex flex-wrap items-center gap-2 transition-[margin] duration-200', 'hidden' => empty($chips)])>
    @foreach ($chips as $chip)
        <span data-ak-chip class="animate-chip-pop inline-flex items-center gap-1.5 rounded-full py-1 pl-2.5 pr-1.5 text-xs font-semibold {{ $chip['chip'] }}">
            <x-dynamic-component :component="'heroicon-o-'.$chip['icon']" class="size-3.5 opacity-70" />
            @if ($chip['label'])
                <span class="font-medium opacity-60">{{ $chip['label'] }}:</span>
            @endif
            {{ $chip['value'] }}
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear="{{ json_encode(['formId' => 'people-filter-form', 'field' => $chip['field'], 'url' => route('people.index')]) }}"
                aria-label="Remover filtro {{ $chip['value'] }}"
                class="!rounded-full !p-0 !text-xs size-4 shrink-0 bg-black/[0.06] !text-current hover:!bg-black/15">
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
