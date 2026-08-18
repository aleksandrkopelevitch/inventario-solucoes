@php
    $isNew = ! $submission->exists;
    $action = $isNew
        ? route('submissions.store', ['filter' => $filters])
        : route('submissions.update', ['submission' => $submission, 'filter' => $filters]);
@endphp

<div class="flex h-full flex-col">
    <header class="border-b border-line px-6 py-5">
        <h2 class="font-display text-lg font-bold text-ink">{{ $isNew ? 'Nova submissão' : 'Editar submissão' }}</h2>
        <p class="mt-1 text-sm text-muted">
            Vincular a solução do catálogo é o que faz o comitê já saber nuvem, criticidade e integrações.
        </p>
    </header>

    <form id="submission-form" class="flex flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
        @csrf
        @unless ($isNew) @method('PATCH') @endunless

        <x-forms.field label="Nome" for="submission-name" required>
            <x-forms.input id="submission-name" name="name" :value="$submission->name" required />
        </x-forms.field>

        <x-forms.field label="Solução" for="submission-solution">
            <x-forms.select id="submission-solution" name="solution_id">
                <option value="">Nenhuma</option>
                @foreach ($solutions as $solution)
                    <option value="{{ $solution->id }}" @selected($submission->solution_id === $solution->id)>{{ $solution->name }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <x-forms.field label="Solicitante" for="submission-requester">
            <x-forms.select id="submission-requester" name="requester_person_id">
                <option value="">Ninguém</option>
                @foreach ($people as $person)
                    <option value="{{ $person->id }}" @selected($submission->requester_person_id === $person->id)>{{ $person->name }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        @unless ($isNew)
            <x-forms.field label="Situação" for="submission-status">
                <x-forms.select id="submission-status" name="status">
                    @foreach (App\Enums\SubmissionStatus::options() as $option)
                        <option value="{{ $option['value'] }}" @selected($submission->status?->value === $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>
        @endunless

        <x-forms.field label="Data do comitê" for="submission-date">
            <x-forms.input type="date" id="submission-date" name="committee_date" :value="$submission->committee_date?->format('Y-m-d')" />
        </x-forms.field>

        <x-forms.field label="Chamado no Leo Resolve" for="submission-ticket">
            <x-forms.input id="submission-ticket" name="ticket_reference" :value="$submission->ticket_reference" />
        </x-forms.field>
    </form>

    <footer class="flex justify-end gap-2 border-t border-line px-6 py-4">
        <x-forms.button type="button" variant="glass" data-ak-panel-close>Cancelar</x-forms.button>
        <x-forms.button form="submission-form" data-ak-ajax="submission-form" data-ak-action="{{ $action }}">
            {{ $isNew ? 'Criar' : 'Salvar' }}
        </x-forms.button>
    </footer>
</div>
