@props([
    'notebook',            // a published Notebook, with `solutions` eager-loaded
    'current' => false,    // the caderno being read: highlighted, not hover-lit
])

{{-- One caderno in a /docs list — the top bar's switcher and the landing's
     rail, which drew the same item twice. The systems it documents ride in the
     filter label (docs-switcher.js) AND on a second line: somebody looking for
     the Digibee manual types "Digibee", not whatever the caderno was called. --}}
<a href="{{ $notebook->knowledgeBaseUrl() }}"
   data-ak-docs-switcher-item
   data-ak-docs-switcher-label="{{ $notebook->name }} {{ $notebook->solutions->pluck('name')->join(' ') }}"
   @class([
       'group flex items-start gap-2 rounded-field px-2.5 py-1.5 no-underline transition-colors',
       'bg-accent-soft' => $current,
       'hover:bg-raised' => ! $current,
   ])>
    <x-heroicon-o-book-open @class(['mt-0.5 size-4 shrink-0 transition-colors', 'text-accent' => $current, 'text-faint group-hover:text-accent' => ! $current]) />
    <span class="min-w-0 flex-1">
        <span @class(['block truncate text-[13.5px]', 'font-semibold text-accent' => $current, 'text-body group-hover:text-ink' => ! $current])>{{ $notebook->name }}</span>
        @if ($notebook->solutions->isNotEmpty())
            <span class="mt-0.5 block truncate text-[11px] text-muted">{{ $notebook->solutions->pluck('name')->join(' · ') }}</span>
        @endif
    </span>
</a>
