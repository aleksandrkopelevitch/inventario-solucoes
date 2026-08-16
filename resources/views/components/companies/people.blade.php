@php
    // The current company rides along in the label so moving someone between
    // companies is never done blind (see `Companies\People::render()`).
    $personOptions = $available->map(fn ($person) => [
        'value' => $person->id,
        'label' => $person->company ? $person->name . ' — ' . $person->company->name : $person->name,
    ])->all();
@endphp

<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-6 shadow-card">
    <h2 class="font-display text-[18px] font-semibold text-ink">Pessoas ({{ $company->people->count() }})</h2>

    {{-- The creator comes BEFORE the list here, unlike the person page's
         "Sistemas" card where it follows it: a company's roster runs to dozens
         of rows, so an action at the end would sit off-screen. It gets its own
         line rather than the title's — the open editor is a full-width select,
         and squeezed into the title row it wrapped the heading to three lines. --}}
    @if ($canEdit && filled($personOptions))
        <x-ui.inline-edit method="POST" :action="route('companies.people.store', $company)"
                         name="person_id" type="select" :options="$personOptions"
                         label="Pessoa" empty="Escolha uma pessoa"
                         edit-class="mt-3 w-full max-w-full" class="mt-3 block">
            <x-ui.add-chip>Adicionar pessoa</x-ui.add-chip>
        </x-ui.inline-edit>
    @endif

    @if ($company->people->isEmpty())
        <p class="mt-2 text-sm text-muted">Nenhuma pessoa cadastrada.</p>
    @else
        <ul class="mt-3 divide-y divide-line">
            @foreach ($company->people as $person)
                <li class="group/row flex items-center gap-3 py-2.5">
                    {{-- No editor over the name, so it stays a full link: the
                         ↗ split only exists to settle a conflict between
                         navigating and editing, and there's none here. --}}
                    <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="sm" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('people.show', $person) }}" class="block truncate text-sm font-medium text-ink hover:text-accent">{{ $person->name }}</a>
                        @if ($person->job_title)<span class="text-xs text-muted">{{ $person->job_title }}</span>@endif
                    </div>

                    @if ($canEdit)
                        <x-ui.row-remove id="company-person-remove-{{ $person->id }}"
                                         :action="route('companies.people.destroy', [$company, $person])"
                                         confirm='Desvincular "{{ $person->name }}" desta empresa? A pessoa continua cadastrada, apenas sem empresa.'
                                         label="Desvincular {{ $person->name }}" />
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
