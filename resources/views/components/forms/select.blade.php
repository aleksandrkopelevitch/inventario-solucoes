@props([
    // Filter-bar mode: size the control to its own content instead of to the
    // column it sits in (x-ui.filter-bar has no columns — it's one wrapping
    // flex row). A `<select>` sizes itself to its LONGEST option, not to the
    // selected one, so a list of 54 company names would otherwise stretch the
    // control across the bar; `max-w-52` caps it and `truncate` ellipsizes the
    // closed display. Default `false`, so panel/form callers are untouched.
    'auto' => false,
])

<div class="relative {{ $auto ? 'w-auto shrink-0' : 'w-full' }}">
    <select {{ $attributes->class([
        'appearance-none rounded-field border border-line-2 bg-surface px-3 py-2 pr-8 text-sm text-ink',
        'w-full' => ! $auto,
        'w-auto max-w-52 truncate' => $auto,
        'transition duration-150 focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]',
        'disabled:bg-raised disabled:text-faint disabled:cursor-not-allowed',
    ]) }}>
        {{ $slot }}
    </select>
    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-faint">
        <svg class="h-4 w-4 fill-current" viewBox="0 0 20 20">
            <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/>
        </svg>
    </div>
</div>
