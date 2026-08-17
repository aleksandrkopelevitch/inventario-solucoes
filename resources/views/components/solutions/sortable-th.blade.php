@props(['column', 'filters' => []])

@php
    $current = $filters['sort'] ?? 'name';
    $isActive = $current === $column || $current === "-{$column}";
    $isDesc = $current === "-{$column}";
@endphp

{{--
    The typography lives on the BUTTON, not on the `<th>`, because the button is
    what renders the label. A form control drops exactly one of these inherited
    properties — `text-transform`, which the UA stylesheet pins to `none` on
    `button`/`input`/`select`/`textarea`, beating inheritance (Tailwind's
    preflight forwards font-family/size/weight/line-height/color, but not this).
    Everything else here inherits fine; splitting the set across the two
    elements is what let `uppercase` sit in this file unrendered. Measured: it
    is the only such case in the app.

    `text-xs font-semibold uppercase tracking-wide text-muted` is the app's
    standard small structural label — the same string as the flowSpec/
    Documentação thread headers, the Person detail-header section labels and the
    Requisitos mínimos heading.
--}}
<th scope="col" {{ $attributes->merge(['class' => 'px-3 py-2.5 text-left']) }}>
    <button type="button"
        data-ak-sort="{{ json_encode(['column' => $column, 'formId' => 'solutions-filter-form', 'url' => route('solutions.index')]) }}"
        class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted transition-colors hover:text-ink {{ $isActive ? '!text-accent' : '' }}">
        {{ $slot }}
        @if ($isActive)
            <x-heroicon-o-chevron-up class="size-3.5 transition-transform {{ $isDesc ? 'rotate-180' : '' }}" />
        @else
            <x-heroicon-o-chevron-up-down class="size-3.5 opacity-40" />
        @endif
    </button>
</th>
