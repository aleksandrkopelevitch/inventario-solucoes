{{-- A submission's AS IS / TO BE, on its own full-height page.

     Its own page rather than a panel inside the workbench, for the same
     reason a diagram's canvas has one: the canvas is a pan/zoom surface
     with its own toolbar and fullscreen, and 340px of a tab is not a place
     anyone can draw an architecture. --}}
<x-layouts.layout :title="$diagram->kind->label()" :fluid="true">
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div class="min-w-0">
                <a href="{{ route('submissions.show', $submission) }}"
                   class="group inline-flex items-center gap-1 text-xs text-accent hover:underline">
                    <x-heroicon-o-arrow-left class="size-3.5 transition-transform duration-150 group-hover:-translate-x-0.5" />
                    {{ $submission->name }}
                </a>
                <h1 class="truncate font-display text-lg font-bold text-ink">{{ $diagram->kind->label() }}</h1>
            </div>

            <p class="max-w-md text-xs text-muted">{{ $diagram->kind->hint() }}</p>
        </div>

        <x-submissions.diagram-workspace :diagram="$diagram" />
    </div>
</x-layouts.layout>
