{{-- The page's name, edited where it is read. `min-w-0` all the way down so a
     long title truncates instead of pushing the toolbar's actions off the bar. --}}
{{-- The arbitrary variant is doing the load-bearing half of the truncation,
     and it has to live here rather than in x-ui.inline-edit: that component
     wraps read mode in an `inline-flex` and deliberately gives it NO
     `max-w-full`, because on the person/company cards the same span sits in a
     shrink-to-fit cell, where a percentage max-width resolves against a width
     that isn't known yet and collapses the box to min-content (it breaks "Não
     informado" across two lines). Here the parent's width IS definite — this
     bar is a flex row — so capping it is both safe and necessary: a flex
     container's min-content size is the SUM of its items' min-content sizes,
     and a `white-space: nowrap` title's min-content is the whole title, so the
     inline-flex shrink-to-fits to the full name and `min-w-0` on the item
     alone can't stop it. --}}
<span id="{{ $domId }}" class="flex min-w-0 flex-1 items-center [&_[data-ak-inline-edit-read]>span]:max-w-full">
    <x-ui.inline-edit :action="$action" name="title" :value="$title"
                      label="Título da página" :editable="$canEdit" class="min-w-0 flex-1">
        {{-- `min-w-0` is what makes the `truncate` beside it real. x-ui.inline-edit
             wraps read mode in an `inline-flex` that deliberately carries no
             `max-w-full` (a percentage max-width inside a shrink-to-fit box
             collapses it to min-content and breaks "Não informado" in half), so
             it shrink-to-fits to its own MIN-CONTENT — and a `white-space:
             nowrap` title's min-content is the whole title. The result was a
             long page name running straight under "Abrir especialista" and
             "Salvar" on any narrow bar; docking the assistant beside the reader
             made that an everyday width rather than a 1024px one. Zeroing this
             item's min-content contribution lets the wrapper collapse to the
             width it was given, and the ellipsis finally has an edge to land
             on. The pencil next to it is `shrink-0`, so it keeps its place. --}}
        <span class="min-w-0 truncate text-sm font-bold text-ink">{{ $title }}</span>
    </x-ui.inline-edit>
</span>
