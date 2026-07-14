@props([
    'name',
    'items' => [],            // preselected: [['value'=>, 'label'=>, 'role'=>null], ...] or plain strings
    'roles' => [],            // optional per-chip role options: [['value'=>, 'label'=>], ...]
    'placeholder' => 'Adicionar e pressionar Enter',
    'searchUrl' => null,      // optional: GET {searchUrl}?q=... -> {"results":[{"id":,"name":}]}. When set, chips can only be added by picking a result — no free-text Enter.
])

@php
    $items = collect($items)->map(fn ($i) => is_array($i)
        ? array_merge(['value' => null, 'label' => null, 'role' => null], $i)
        : ['value' => $i, 'label' => $i, 'role' => null]);

    $config = ['name' => $name, 'roles' => array_values($roles), 'searchUrl' => $searchUrl];
@endphp

{{-- Multi-select chips com "papel" (role) opcional por chip. Novos chips entram
     no Enter e saem pelo × (resources/js/modules/chips.js). Submete como
     name[index][value], name[index][label], name[index][role]. Com
     `search-url`, o Enter só adiciona um chip vindo da lista de resultados
     buscada no servidor — não cria chip a partir de texto livre. --}}
<div
    data-ak-chips="{{ json_encode($config) }}"
    data-ak-chips-next="{{ $items->count() }}"
    {{ $attributes->class(['flex w-full flex-col gap-2 rounded-field border border-line-2 bg-surface p-2 focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]']) }}
>
    <div data-ak-chips-list class="flex flex-wrap gap-1.5 empty:hidden">
        @foreach ($items as $i => $item)
            <span data-ak-chip class="inline-flex items-center gap-1 rounded-full bg-accent-soft py-1 pl-2.5 pr-1 text-xs font-semibold text-ink ring-1 ring-accent-line">
                <span>{{ $item['label'] }}</span>
                @if (! empty($roles))
                    <select name="{{ $name }}[{{ $i }}][role]" class="rounded bg-transparent text-xs text-ink focus:outline-none">
                        @foreach ($roles as $role)
                            <option value="{{ $role['value'] }}" @selected(($item['role'] ?? null) === $role['value'])>{{ $role['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" data-ak-chip-remove class="ml-0.5 rounded-full px-1 leading-none text-muted hover:bg-accent-line hover:text-ink" aria-label="Remover">&times;</button>
                <input type="hidden" name="{{ $name }}[{{ $i }}][value]" value="{{ $item['value'] }}">
                <input type="hidden" name="{{ $name }}[{{ $i }}][label]" value="{{ $item['label'] }}">
            </span>
        @endforeach
    </div>

    <div class="relative">
        <input
            type="text"
            data-ak-chips-input
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            class="w-full bg-transparent px-1 py-1 text-sm text-ink placeholder-faint focus:outline-none"
        />
        @if ($searchUrl)
            <div data-ak-chips-results class="absolute inset-x-0 top-full z-10 mt-1 hidden max-h-48 overflow-y-auto rounded-field border border-line-2 bg-surface py-1 shadow-lg"></div>
        @endif
    </div>
</div>
