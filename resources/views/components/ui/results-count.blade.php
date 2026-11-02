{{-- The live result counter of a catalog index (App\View\Components\Concerns\ResultsCountSlot).
     `hero` sits inside x-ui.hero-panel, so it is glow-ink based rather than
     text-accent/text-muted: it renders on the gradient, not the canvas. --}}
@if ($variant === 'hero')
    <span id="{{ $domId }}" class="ml-2 align-middle text-base font-semibold text-[color:var(--color-glow-ink)]/60">
        <span class="text-[color:var(--color-glow-ink)]">{{ $count }}</span> {{ $noun }}
    </span>
@else
    <span id="{{ $domId }}" class="text-sm text-muted">
        {{ $count }} {{ $noun }}
    </span>
@endif
