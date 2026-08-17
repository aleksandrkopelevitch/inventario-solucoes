{{--
    This element is the FOOTER of x-ui.filter-bar, not a standalone row: it
    carries the bar's bottom chrome itself (border-t, recessed ground, the
    card's inner bottom radius) and keeps hiding itself when there is nothing
    active. That's deliberate — the updatable slot replaces THIS node by id,
    so chrome living in a parent wrapper would survive as an empty strip once
    the last filter is cleared.

    Before, this row sat 20px below the controls and 20px above the results,
    belonging to neither. Now it is inside the same card as the controls that
    produced it.
--}}
<div id="{{ $domId }}" @class([
    'flex flex-wrap items-center gap-2 rounded-b-[13px] border-t border-line bg-canvas px-3 py-2',
    'hidden' => empty($chips),
])>
    @foreach ($chips as $chip)
        {{-- `transition duration-150` is what execute-filters.js's CHIP_EXIT_MS
             waits for: it adds `scale-90 opacity-0` to this span and fires the
             filter change 150ms later. Without a transition here that wait
             bought an instant disappearance — the comment in that module
             described an exit animation the markup never had. --}}
        <span data-ak-chip class="animate-chip-pop inline-flex items-center gap-1.5 rounded-full py-1 pl-2.5 pr-1.5 text-xs font-semibold transition duration-150 {{ $chip['chip'] }}">
            <x-dynamic-component :component="'heroicon-o-'.$chip['icon']" class="size-3.5 opacity-70" />
            @if ($chip['label'])
                <span class="font-medium opacity-60">{{ $chip['label'] }}:</span>
            @endif
            {{ $chip['value'] }}
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear="{{ json_encode(['formId' => 'companies-filter-form', 'field' => $chip['field'], 'url' => route('companies.index')]) }}"
                aria-label="Remover filtro {{ $chip['value'] }}"
                class="!rounded-full !p-0 !text-xs size-4 shrink-0 bg-black/[0.06] !text-current hover:!bg-black/15">
                &times;
            </x-forms.button>
        </span>
    @endforeach

    @if (count($chips))
        <x-forms.button type="button" variant="ghost"
            data-ak-filters-clear-all="{{ json_encode(['formId' => 'companies-filter-form', 'url' => route('companies.index')]) }}"
            class="!rounded-none !p-0 !text-xs ml-auto underline decoration-line-2 underline-offset-2 hover:!bg-transparent">
            Limpar tudo
        </x-forms.button>
    @endif
</div>
