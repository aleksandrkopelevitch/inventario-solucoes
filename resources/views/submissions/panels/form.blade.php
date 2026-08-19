{{--
    New submission only — there is no edit panel. Every other field
    (solicitante, data do comitê, chamado, status) is edited in place on the
    detail header once the record exists, so asking for it here would mean
    filling it in twice, once blind.
--}}
<div class="flex h-full flex-col">
    <header class="border-b border-line px-6 py-5">
        <h2 class="font-display text-lg font-bold text-ink">Nova submissão</h2>
        <p class="mt-1 text-sm text-muted">
            Vincular a solução do catálogo é o que faz o comitê já saber nuvem, criticidade e integrações.
        </p>
    </header>

    <form id="submission-form" class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
        @csrf

        <x-forms.field label="Nome" for="submission-name" required>
            <x-forms.input id="submission-name" name="name" required autofocus />
        </x-forms.field>

        <x-forms.field label="Solução" for="submission-solution" hint="Deixe em branco se ainda não estiver no catálogo.">
            <x-forms.select id="submission-solution" name="solution_id">
                <option value="">Nenhuma (ainda)</option>
                @foreach ($solutions as $solution)
                    <option value="{{ $solution->id }}">{{ $solution->name }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>
    </form>

    <footer class="flex justify-end gap-2 border-t border-line px-6 py-4">
        <x-forms.button type="button" variant="glass" data-ak-panel-close>Cancelar</x-forms.button>
        <x-forms.button form="submission-form" data-ak-ajax="submission-form"
            data-ak-action="{{ route('submissions.store', ['filter' => $filters]) }}">
            Criar
        </x-forms.button>
    </footer>
</div>
