{{-- Sits inside <x-ui.hero-panel> (see people/index.blade.php) — glow-ink
     based, not text-accent/text-muted, since it renders on the gradient. --}}
<span id="{{ $domId }}" class="ml-2 align-middle text-base font-semibold text-[color:var(--color-glow-ink)]/60">
    <span class="text-[color:var(--color-glow-ink)]">{{ $count }}</span> {{ $count === 1 ? 'pessoa' : 'pessoas' }}
</span>
