@props([
    'name',
    'items' => [],            // preselected: [['value'=>, 'label'=>, 'role'=>null], ...] or plain strings
    'roles' => [],            // optional per-chip role options: [['value'=>, 'label'=>], ...]
    'placeholder' => 'Adicionar e pressionar Enter',
    'searchUrl' => null,      // optional: GET {searchUrl}?q=... -> {"results":[{"id":,"name":}]}. When set, chips can only be added by picking a result — no free-text Enter.
    'form' => null,           // optional: id of an external <form> — lets this component render outside the <form> tag (e.g. a sidebar) while its hidden inputs still submit with it
    'centered' => false,      // optional: the search input opens in a centered overlay (same idea as the Ctrl+K palette in ecosystem-map.js) instead of an inline dropdown — pick this for a standalone search field; opened only by clicking the trigger, never a keyboard shortcut (a page can have more than one `centered` field, so a global shortcut would be ambiguous about which one to open)
    'triggerHidden' => false, // optional (centered only): render the trigger button hidden so the overlay is opened elsewhere (e.g. the flowSpec composer's 📎 attach menu does `trigger.click()`) while the selected chips still show wherever this component is placed
])

@php
    $items = collect($items)->map(fn ($i) => is_array($i)
        ? array_merge(['value' => null, 'label' => null, 'role' => null], $i)
        : ['value' => $i, 'label' => $i, 'role' => null]);

    $config = ['name' => $name, 'roles' => array_values($roles), 'searchUrl' => $searchUrl, 'form' => $form, 'centered' => $centered];
@endphp

{{-- Multi-select chips with an optional "role" per chip. New chips are added
     on Enter (or picking a search result) and removed via × (resources/js/modules/chips.js).
     Submits as name[index][value], name[index][label], name[index][role]. With
     `search-url`, Enter only adds a chip coming from the server-side search
     results list — it never creates a chip from free text.

     `data-ak-chips-list` is hidden via the `hidden` class computed here
     (initial state) and kept in sync by `chips.js::syncListVisibility` on
     every add/remove — it doesn't use Tailwind's `empty:hidden` utility: CSS
     `:empty` requires ZERO child nodes, and the whitespace left by Blade's
     indentation between `<div>`/`@foreach`/`@endforeach`/`</div>` already
     counts as a child, so `:empty` never matched (real finding: the "empty"
     list still had a phantom text node, and the parent flex-col's `gap-2`
     still reserved space for it — the search box was born with extra
     breathing room on top, looking like broken padding). --}}
<div
    data-ak-chips="{{ json_encode($config) }}"
    data-ak-chips-next="{{ $items->count() }}"
    {{ $attributes->class([
        'flex w-full flex-col gap-2',
        'rounded-field border border-line-2 bg-surface p-2 focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]' => ! $centered,
    ]) }}
>
    <div data-ak-chips-list class="flex flex-wrap gap-1.5 {{ $items->isEmpty() ? 'hidden' : '' }}">
        @foreach ($items as $i => $item)
            <span data-ak-chip class="inline-flex items-center gap-1 rounded-full bg-accent-soft py-1 pl-2.5 pr-1 text-xs font-semibold text-ink ring-1 ring-accent-line">
                <span>{{ $item['label'] }}</span>
                @if (! empty($roles))
                    <select name="{{ $name }}[{{ $i }}][role]" @if ($form) form="{{ $form }}" @endif class="rounded bg-transparent text-xs text-ink focus:outline-none">
                        @foreach ($roles as $role)
                            <option value="{{ $role['value'] }}" @selected(($item['role'] ?? null) === $role['value'])>{{ $role['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" data-ak-chip-remove class="ml-0.5 rounded-full px-1 leading-none text-muted hover:bg-accent-line hover:text-ink" aria-label="Remover">&times;</button>
                <input type="hidden" name="{{ $name }}[{{ $i }}][value]" value="{{ $item['value'] }}" @if ($form) form="{{ $form }}" @endif>
                <input type="hidden" name="{{ $name }}[{{ $i }}][label]" value="{{ $item['label'] }}" @if ($form) form="{{ $form }}" @endif>
            </span>
        @endforeach
    </div>

    @if ($centered)
        <button type="button" data-ak-chips-trigger @class([
                'flex w-full items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-2 text-left text-sm text-faint transition hover:border-accent-line focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]' => ! $triggerHidden,
                'hidden' => $triggerHidden,
            ])>
            <x-heroicon-o-magnifying-glass class="size-4 shrink-0" />
            <span class="truncate">{{ $placeholder }}</span>
        </button>

        {{-- Centered overlay — same visual pattern as `data-eco-search-overlay`
             in ecosystem-map.blade.php. `position: fixed` ignores the parent
             container's layout (no ancestor here uses transform/filter), so
             it can stay nested inside `[data-ak-chips]` without escaping the
             card — and so `closest('[data-ak-chips]')` in chips.js still
             finds the input/results normally. --}}
        <div data-ak-chips-overlay class="fixed inset-0 z-40 hidden items-start justify-center bg-ink/25 pt-24 backdrop-blur-[1px]">
            <div class="w-full max-w-md rounded-xl border border-line bg-surface shadow-[0_12px_32px_rgba(16,24,40,.24)]">
                <div class="flex items-center gap-2 border-b border-line px-3 py-2.5">
                    <x-heroicon-o-magnifying-glass class="size-4 shrink-0 text-faint" />
                    <input
                        type="text"
                        data-ak-chips-input
                        placeholder="{{ $placeholder }}"
                        autocomplete="off"
                        class="w-full bg-transparent px-1 py-1 text-sm text-ink placeholder-faint focus:outline-none"
                    />
                    <button type="button" data-ak-chips-overlay-close aria-label="Fechar"
                        class="shrink-0 rounded-md p-1 text-faint hover:bg-raised hover:text-ink">
                        <x-heroicon-o-x-mark class="size-4" />
                    </button>
                </div>
                <div data-ak-chips-results class="hidden max-h-72 overflow-y-auto p-1.5"></div>
            </div>
        </div>
    @else
        <div class="relative">
            <div class="flex items-center gap-1.5">
                @if ($searchUrl)
                    <x-heroicon-o-magnifying-glass class="size-4 shrink-0 text-faint" />
                @endif
                <input
                    type="text"
                    data-ak-chips-input
                    placeholder="{{ $placeholder }}"
                    autocomplete="off"
                    class="w-full bg-transparent px-1 py-1 text-sm text-ink placeholder-faint focus:outline-none"
                />
            </div>
            @if ($searchUrl)
                <div data-ak-chips-results class="absolute inset-x-0 top-full z-10 mt-1 hidden max-h-48 overflow-y-auto rounded-field border border-line-2 bg-surface py-1 shadow-lg"></div>
            @endif
        </div>
    @endif
</div>
