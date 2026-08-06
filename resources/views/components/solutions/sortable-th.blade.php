@props(['column', 'filters' => []])

@php
    $current = $filters['sort'] ?? 'name';
    $isActive = $current === $column || $current === "-{$column}";
    $isDesc = $current === "-{$column}";
@endphp

<th scope="col" {{ $attributes->merge(['class' => 'px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted']) }}>
    <button type="button"
        data-ak-sort="{{ json_encode(['column' => $column, 'formId' => 'solutions-filter-form', 'url' => route('solutions.index')]) }}"
        class="inline-flex items-center gap-1 transition-colors hover:text-ink {{ $isActive ? '!text-accent' : '' }}">
        {{ $slot }}
        @if ($isActive)
            <x-heroicon-o-chevron-up class="size-3.5 transition-transform {{ $isDesc ? 'rotate-180' : '' }}" />
        @else
            <x-heroicon-o-chevron-up-down class="size-3.5 opacity-40" />
        @endif
    </button>
</th>
