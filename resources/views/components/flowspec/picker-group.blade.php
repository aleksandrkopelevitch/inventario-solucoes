@props(['title', 'eyebrow' => null, 'count' => 0])

{{-- One collapsible container in the document picker. Collapsed by default:
     with hundreds of imported pages, an expanded list is a wall — the filter in
     flowspec-chat.js opens the groups that match what was typed. --}}
<details data-ak-fs-picker-group class="rounded-field border border-line">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
        <x-heroicon-o-chevron-right class="size-3.5 shrink-0 text-muted transition [details[open]_&]:rotate-90" />
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold text-ink">{{ $title }}</span>
            @if ($eyebrow)
                <span class="block text-[11px] text-faint">{{ $eyebrow }}</span>
            @endif
        </span>
        <span data-ak-fs-picker-group-count class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-[11px] font-medium text-muted">{{ $count }}</span>
        <x-forms.button type="button" variant="ghost" data-ak-fs-picker-visible
            class="!shrink-0 !px-2 !py-0.5 !text-[11px] !font-medium">Marcar visíveis</x-forms.button>
    </summary>

    <ul class="flex flex-col gap-1 border-t border-line px-2 py-2 pl-2">
        {{ $slot }}
    </ul>
</details>
