@php
    $tabBase = ['targetContainerId' => 'attribute-options-tabs', 'activeClasses' => ['bg-accent-soft', 'text-ink'], 'inactiveClasses' => ['text-muted']];
@endphp

<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div>
        <h2 class="font-display text-lg font-semibold text-ink">Gerenciar atributos</h2>
        <p class="mt-0.5 text-xs text-muted">Edite os valores disponíveis para os atributos de soluções.</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<div class="flex max-h-[70vh]">
    <nav role="tablist" aria-label="Grupos de atributos" class="w-44 shrink-0 space-y-0.5 overflow-y-auto border-r border-line p-3">
        @foreach ($groups as $group)
            <x-forms.button type="button" variant="ghost"
                id="attribute-options-trigger-{{ $group->value }}"
                role="tab"
                aria-controls="attribute-options-tab-{{ $group->value }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                tabindex="{{ $loop->first ? '0' : '-1' }}"
                data-ak-tabs="{{ json_encode(array_merge($tabBase, ['targetId' => 'attribute-options-tab-' . $group->value, 'selectedOnInit' => $loop->first])) }}"
                class="w-full !px-3 text-left !font-medium">
                {{ $group->label() }}
            </x-forms.button>
        @endforeach
    </nav>

    <div id="attribute-options-tabs" class="min-w-0 flex-1 overflow-y-auto px-5 py-4">
        @foreach ($groups as $group)
            <div id="attribute-options-tab-{{ $group->value }}"
                role="tabpanel"
                aria-labelledby="attribute-options-trigger-{{ $group->value }}"
                @class(['hidden' => ! $loop->first])>
                <h3 class="mb-3 text-sm font-semibold text-ink">{{ $group->label() }}</h3>
                <x-attribute-options.group-list :group="$group" />
            </div>
        @endforeach
    </div>
</div>
