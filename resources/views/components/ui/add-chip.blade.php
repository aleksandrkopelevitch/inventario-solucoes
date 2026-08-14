{{--
    The dashed "+ Adicionar …" chip that opens an x-ui.inline-edit creator
    (`method="POST"`). Every card that attaches a relation in place renders one
    — contacts, systems, a company's people, a company's systems, a solution's
    owners — and they were five copies of the same class string, which is one
    edit away from five slightly different chips.

    It reacts to hover on the whole creator (`group/ie`, the class
    x-ui.inline-edit puts on its root), not just on itself: the chip IS the
    creator's read mode, so the two are one target. The plain `hover:` twin
    keeps it alive if it's ever used outside that group.
--}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full border border-dashed border-line-2 px-2.5 py-1',
    'text-xs font-medium text-muted transition-colors',
    'group-hover/ie:border-accent-line group-hover/ie:bg-accent-soft group-hover/ie:text-accent',
    'hover:border-accent-line hover:bg-accent-soft hover:text-accent',
]) }}>
    <x-heroicon-o-plus class="size-3.5" />
    {{ $slot }}
</span>
