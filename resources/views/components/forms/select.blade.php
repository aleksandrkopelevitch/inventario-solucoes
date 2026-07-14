<div class="relative w-full">
    <select {{ $attributes->class([
        'w-full appearance-none rounded-field border border-line-2 bg-surface px-3 py-2 pr-8 text-sm text-ink',
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
