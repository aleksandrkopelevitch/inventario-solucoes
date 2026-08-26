@props([
    // The Diagram this page points at, or null.
    'diagram' => null,
    // Every diagram, as [['value' => id, 'label' => name], …].
    'options' => [],
    // PATCH endpoint that writes the link (…/documentation/{page}/diagram).
    // Null/blank means the viewer can't edit — read-only text, as everywhere.
    'action' => null,
])

{{--
    The page's link to a drawing, in the documentation editor's top bar.

    One field, edited in place, exactly like every other datum in this app
    (x-ui.inline-edit): read mode names the diagram, the pencil opens a select,
    and the blank option IS the unlink — nothing else is needed, because the
    relation is a single nullable FK on the page.

    Two deliberate choices here:

    - **The ↗ goes to the diagram's own page, and the words open the editor.**
      Same split the rest of the app keeps (words read/edit, icon travels), and
      it matters more than usual here: the drawing is a shared record, so
      "open it where it lives" and "point this page somewhere else" are two
      genuinely different intentions sitting a few pixels apart.
    - **A select, not a search panel.** The list is tens of rows; a picker
      panel would be chrome without a purpose. See LinksPageDiagram if that
      stops being true.

    Saving navigates (the endpoint answers with a `redirect`) because linking
    changes the shape of the screen: the tab pair and the canvas appear or
    disappear with it.
--}}
<x-ui.inline-edit name="diagram_id" type="select" :options="$options"
                  :value="$diagram?->id" :nullable="true"
                  :action="$action" :editable="filled($action)"
                  label="Diagrama vinculado" empty="Sem diagrama"
                  :link="$diagram ? route('diagrams.show', $diagram) : null"
                  link-label="diagrama"
                  edit-class="min-w-56" input-class="!text-xs !font-medium"
                  class="shrink-0 max-lg:hidden">
    <span class="inline-flex items-center gap-1.5 rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">
        <x-heroicon-o-share class="size-3.5 shrink-0 {{ $diagram ? 'text-accent' : 'text-faint' }}" />
        {{ $diagram?->name ?? 'Sem diagrama' }}
    </span>
</x-ui.inline-edit>
