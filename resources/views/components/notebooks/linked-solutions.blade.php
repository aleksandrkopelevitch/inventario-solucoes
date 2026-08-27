@php
    $selected = $linked->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values();
@endphp

{{-- Which solutions this caderno documents. Its own slot, and its own form:
     linking is a different decision from naming, answers with a different set
     of slots (every affected solution's detail card), and is the one gesture
     here that a reader of the pages might want without touching anything else.

     The chips' hidden inputs submit as `solutions[i][value]`, which is the
     shape SyncNotebookSolutionsRequest normalizes — an empty set is valid and
     means "no solution in particular". --}}
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
        <form id="notebook-solutions-form" class="mt-3">
            @csrf
            @method('PATCH')

            <x-forms.chips name="solutions" :items="$selected"
                placeholder="Buscar solução e pressionar Enter"
                :search-url="route('solutions.search')" />

            <x-forms.button data-ak-ajax="notebook-solutions-form" data-ak-action="{{ $action }}"
                class="mt-3 !h-9 !text-sm">
                Salvar vínculos
            </x-forms.button>
        </form>
    @else
        @if ($linked->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($linked as $solution)
                    <span class="inline-flex items-center rounded-full bg-accent-soft px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-accent-line">
                        {{ $solution->name }}
                    </span>
                @endforeach
            </div>
        @else
            <p class="mt-3 text-sm text-muted">Nenhuma solução vinculada.</p>
        @endif
    @endcan
</div>
