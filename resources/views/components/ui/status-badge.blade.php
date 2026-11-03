@props([
    // Any status enum with label() and badgeClass() — DiagramStatus,
    // ContextExtractionState, ConformanceVerdict…
    'status',
    // `sm` sits in a list row; `md` stands on its own (a page's top bar).
    'size' => 'sm',
])

{{-- The tinted pill of a status: its label on its own tone, which the enum
     owns (`badgeClass()`), so a status reads the same on every screen. --}}
<span {{ $attributes->class([
    'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 font-medium',
    'text-[11px]' => $size === 'sm',
    'text-xs' => $size === 'md',
    $status->badgeClass(),
]) }}>{{ $status->label() }}</span>
