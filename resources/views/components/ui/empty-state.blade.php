{{-- The "there's nothing here yet" block: an illustration, a line saying what
     is missing, and a line saying what to do about it. A dashed frame like the
     bare text version it replaces, so an empty column still reads as a
     placeholder and not as a broken card.

     `illustration` names a component under `resources/views/components/
     illustrations/` (`integrations` → x-illustrations.empty-integrations). Those
     are inlined unDraw SVGs painted with `currentColor` plus the app's tokens,
     which is why the color is set here, once, rather than inside each drawing. --}}
@props([
    'illustration',
    'title',
    'description' => null,
    // Illustrations don't share an aspect ratio (a wide diagram, a tall page),
    // so the caller caps the one it uses instead of a single width fitting none.
    'illustrationClass' => 'max-w-[220px]',
])

<div {{ $attributes->class(['flex flex-col items-center gap-4 rounded-field border border-dashed border-line-2 px-4 py-8 text-center']) }}>
    <x-dynamic-component :component="'illustrations.empty-' . $illustration"
                         class="w-full {{ $illustrationClass }} text-accent" />

    <div>
        <p class="text-sm font-medium text-ink">{{ $title }}</p>
        @if ($description)
            <p class="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-muted">{{ $description }}</p>
        @endif
    </div>
</div>
