{{-- "Desenhar esta página" — the question asked BEFORE anything is generated:
     a new diagram of this caderno, under a name the author picks, or a new
     drawing over one the caderno already has.

     One radio group (`target`), "Criar novo" first and checked. Overwriting
     keeps the diagram's name and slug, so every page citing it goes on
     working and shows the new drawing; the warning under the list says so,
     because "substituir" alone reads as losing the citation too.

     The confirm keeps the three hooks the menu items used to carry
     (`data-ak-ajax-target="_blank"` + `-pending`): the model call is
     synchronous and can take a minute, so the blank tab has to be opened
     inside THIS click, and it shows what it is waiting for. No `type` on the
     button — it must stay a submit, or Enter in the name field silently stops
     working (AGENTS.md). --}}
<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="font-display text-lg font-semibold text-ink">
            {{ $model ? 'Desenhar: ' . $model->label() : 'Desenhar esta página' }}
        </h2>
        <p class="mt-0.5 text-xs text-muted">
            O diagrama fica no caderno <span class="font-medium text-ink">{{ $notebook->name }}</span>, lido a partir de "{{ $page->title }}".
        </p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<form id="diagram-target-form" class="flex flex-col gap-4 px-5 py-4">
    @csrf

    <div class="rounded-field border border-line p-3">
        <x-forms.radio id="diagram-target-new" name="target" :value="$newTarget" checked>
            Criar um diagrama novo
        </x-forms.radio>
        <x-forms.field label="Nome do diagrama" for="diagram-target-name" name="name" required class="mt-2.5 pl-7">
            <x-forms.input id="diagram-target-name" name="name" :value="$name" maxlength="255" class="!h-9 !text-sm" />
        </x-forms.field>
    </div>

    @if ($diagrams->isNotEmpty())
        <div class="rounded-field border border-line p-3">
            <p class="text-sm font-medium text-ink">Ou substituir um diagrama deste caderno</p>
            <div class="mt-2.5 flex max-h-[40vh] flex-col gap-2 overflow-y-auto">
                @foreach ($diagrams as $diagram)
                    <x-forms.radio id="diagram-target-{{ $diagram->slug }}" name="target" :value="$diagram->slug">
                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="size-2 shrink-0 rounded-full {{ $diagram->status->dotClass() }}" title="{{ $diagram->status->label() }}"></span>
                                <span class="truncate">{{ $diagram->name }}</span>
                            </span>
                            <x-diagrams.solution-chips :solutions="$diagram->participants" :linked="false" />
                        </span>
                    </x-forms.radio>
                @endforeach
            </div>
            <p class="mt-3 flex items-start gap-1.5 text-xs text-muted">
                <x-heroicon-o-information-circle class="mt-px size-4 shrink-0" />
                O desenho atual é trocado pelo novo. Nome e endereço continuam os mesmos, e as páginas que citam o diagrama passam a mostrar o desenho novo.
            </p>
        </div>
    @endif

    <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
        <x-forms.button type="button" variant="ghost" data-close>Cancelar</x-forms.button>
        <x-forms.button data-ak-ajax="diagram-target-form" data-ak-action="{{ $action }}"
            data-ak-ajax-target="_blank"
            data-ak-ajax-pending="{{ $model ? 'Desenhando: ' . $model->label() . '…' : 'Desenhando o fluxo desta página…' }}">
            <x-heroicon-o-sparkles class="size-4" /> Desenhar
        </x-forms.button>
    </div>
</form>
