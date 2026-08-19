{{--
    The "Nome da Solução ›" prefix of the documentation editor's top bar — what
    says which solution (or group) the page on screen belongs to once the pages
    rail is collapsed and its own header is off screen.

    The ↗ is the ONLY navigation affordance here (same rule as the detail
    pages': the words stay words, the icon travels), and it replaced the back
    arrow that used to sit in this bar — that arrow pointed at the solution
    without ever saying so.

    NO display utility on the outer <span> on purpose: one instance of this
    component is shown/hidden by `toggle.js` flipping Tailwind's `hidden`, and
    a second `display` utility on the same element turns that into a coin toss
    decided by stylesheet order (see the note in AGENTS.md). The layout lives on
    the inner span instead, which nothing toggles.
--}}
@props([
    'label',
    'url',
])

{{-- `min-w-0` (a sizing utility, not a display one — safe on the toggled copy)
     is what lets the crumb give way instead of shoving the page title under
     the tab switcher on a narrow screen: a flex item's default `min-width:auto`
     refuses to shrink past its content, and this bar has four things in it. --}}
<span {{ $attributes->class(['min-w-0']) }}>
    {{-- `flex`, not `inline-flex`: an inline-flex box shrink-to-fits against
         its OWN content and happily overflows a parent that was squeezed, which
         on a phone drew the crumb straight through the page title next to it. A
         block-level flex box takes the width it's actually given, and its items
         shrink inside it. (Safe here — nothing toggles a `display` on this inner
         span; the outer one is the toggled element.) --}}
    <span class="flex min-w-0 max-w-[7rem] items-center gap-1 sm:max-w-[10rem] lg:max-w-[16rem]">
        <span class="truncate text-sm text-muted">{{ $label }}</span>
        <x-ui.external-link :href="$url" :label="$label" class="text-muted" />
        <span class="text-sm text-faint" aria-hidden="true">›</span>
    </span>
</span>
