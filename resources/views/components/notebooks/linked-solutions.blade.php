@php
    $selected = $linked->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values();
@endphp

{{-- Which solutions this caderno documents. Its own slot, and its own form:
     linking is a different decision from naming, answers with a different set
     of slots (every affected solution's detail card), and is the one gesture
     here that a reader of the pages might want without touching anything else.
     SyncNotebookSolutionsRequest normalizes what x-ui.solution-picker posts. --}}
<div id="{{ $domId }}">
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <h3 class="font-display text-base font-semibold text-ink">Soluções documentadas</h3>
            <p class="mt-0.5 text-sm text-muted">
                Este caderno aparece na página de cada solução vinculada.
            </p>
        </div>
    </div>

    @can('update', $notebook)
        <x-ui.solution-picker form-id="notebook-solutions-form" :action="$action" :items="$selected"
            submit="Salvar vínculos" class="mt-3" />
    @else
        <x-ui.solution-chips :solutions="$linked" class="mt-3" empty="Nenhuma solução vinculada." />
    @endcan
</div>
