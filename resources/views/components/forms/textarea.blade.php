@props(['rows' => 4])

<textarea
    rows="{{ $rows }}"
    {{ $attributes->class([
        'block w-full resize-y rounded-field border border-line-2 bg-surface px-3 py-2 text-sm text-ink placeholder-faint',
        'transition duration-150 focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]',
        'disabled:bg-raised disabled:text-faint disabled:cursor-not-allowed',
    ]) }}
>{{ $slot }}</textarea>
