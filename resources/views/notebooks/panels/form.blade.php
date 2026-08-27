@php
    // The chips component wants [['value'=>, 'label'=>], …]; the picker offers
    // every solution, with the linked ones preselected.
    $selected = $solutions->whereIn('id', $linked)
        ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])
        ->values();
@endphp

{{-- Create/edit a caderno. Name and links are two endpoints (see
     SaveNotebookRequest), so this panel carries two forms: naming answers with
     the catalog slot, linking with the chips' own slot plus every affected
     solution card. On CREATE there is nothing to link to yet — the caderno has
     no id — so only the name form renders and the links are set right after,
     from the caderno itself. --}}
<div class="flex h-full flex-col">
    <div class="border-b border-line px-6 py-5">
        <h2 class="font-display text-lg font-semibold text-ink">
            {{ $notebook ? 'Editar caderno' : 'Novo caderno' }}
        </h2>
        <p class="mt-1 text-sm text-muted">
            Um caderno é um corpo de documentação — como um espaço no GitBook.
        </p>
    </div>

    <div class="flex-1 overflow-y-auto px-6 py-5">
        <form id="notebook-form" class="flex flex-col gap-4">
            @csrf
            @if ($notebook)
                @method('PATCH')
            @endif

            <x-forms.field label="Nome" for="notebook-name"
                hint="Aparece no catálogo, na trilha de páginas e no link público.">
                <x-forms.input id="notebook-name" name="name" :value="$notebook?->name"
                    placeholder="Ex.: Integrações Leo 360" autofocus />
            </x-forms.field>
        </form>

        @if ($notebook)
            <div class="mt-6 border-t border-line pt-6">
                <x-notebooks.linked-solutions :notebook="$notebook" />
            </div>
        @else
            <p class="mt-6 rounded-field border border-dashed border-line px-4 py-4 text-sm text-muted">
                Depois de criar, você poderá vincular as soluções que este caderno documenta.
            </p>
        @endif
    </div>

    <div class="flex items-center justify-end gap-2 border-t border-line px-6 py-4">
        <x-forms.button type="button" variant="ghost" data-ak-panel-close>Cancelar</x-forms.button>
        <x-forms.button form="notebook-form" data-ak-ajax="notebook-form" data-ak-action="{{ $action }}">
            {{ $notebook ? 'Salvar' : 'Criar caderno' }}
        </x-forms.button>
    </div>
</div>
