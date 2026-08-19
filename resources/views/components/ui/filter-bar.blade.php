@props([
    // The AJAX-only form the controls belong to. It deliberately has no
    // method/action — execute-filters.js serializes every `filter[...]` field
    // and preventDefault()s any native submit (see AGENTS.md).
    'formId',
])

{{--
    One bar for every list page: /solutions, /companies, /people and
    /documentation. Replaces the per-page grid each of them used to calibrate
    by hand (`lg:grid-cols-5` here, `sm:grid-cols-4` there) with a single
    wrapping flex row, so the number of filters a page has stops being a
    layout decision: seven controls break onto a second line, one control
    takes only the width it needs.

    It's also a CARD, like everything else on these pages (the hero panel, the
    catalog summary, the results). Before, the filter controls were the only
    bare thing on the canvas — they read as leftovers between two solid
    objects, and the search field's `max-w-md` right edge lined up with
    nothing at all.

    `$footer` is where the active-filter chips go. The chips component IS the
    footer element (it carries the border-t and hides itself when there are no
    chips) — don't wrap it in a container here, or the updatable-slot swap
    would replace the chips and leave an empty strip of chrome behind.
--}}
<form id="{{ $formId }}" {{ $attributes->class(['mb-4']) }}>
    <div class="rounded-card border border-line bg-surface shadow-card">
        <div class="flex flex-wrap items-center gap-2 p-2.5">
            {{ $search }}

            @if ($slot->isNotEmpty())
                {{-- Hidden below sm, where the search already takes its own
                     line and the rule would just dangle at the start of the
                     next one. --}}
                <span aria-hidden="true" class="mx-1 my-0.5 hidden w-px self-stretch bg-line sm:block"></span>

                {{ $slot }}
            @endif
        </div>

        {{ $footer ?? '' }}
    </div>
</form>
