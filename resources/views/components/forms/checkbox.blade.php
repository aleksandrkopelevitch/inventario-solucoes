@props(['checked' => false, 'value' => '1'])

<div class="group grid size-4 grid-cols-1">
    <input
        type="checkbox"
        value="{{ $value }}"
        {{ $checked ? 'checked' : '' }}
        {{ $attributes->class([
            'col-start-1 row-start-1 appearance-none rounded border border-line-2 bg-surface cursor-pointer',
            'checked:border-accent checked:bg-accent',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            'disabled:border-line-2 disabled:bg-raised disabled:cursor-not-allowed',
        ]) }}
    />
    <svg viewBox="0 0 14 14" fill="none"
         class="pointer-events-none col-start-1 row-start-1 size-3.5 self-center justify-self-center stroke-white">
        <path d="M3 8L6 11L11 3.5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="opacity-0 group-has-checked:opacity-100" />
    </svg>
</div>
