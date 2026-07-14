@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'name' => null,
    'required' => false,
    'error' => null,
])

@php
    $resolvedError = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
@endphp

<div {{ $attributes->class(['flex w-full flex-col gap-1.5']) }}>
    @if ($label)
        <x-forms.label :for="$for">
            {{ $label }}
            @if ($required)<span class="text-crit" aria-hidden="true">*</span>@endif
        </x-forms.label>
    @endif

    {{ $slot }}

    @if ($hint && ! $resolvedError)
        <p class="text-xs text-muted">{{ $hint }}</p>
    @endif

    @if ($resolvedError)
        <p class="text-xs text-crit">{{ $resolvedError }}</p>
    @endif
</div>
