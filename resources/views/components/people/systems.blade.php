@php
    $roleOptions = collect($roles)->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()])->all();
    $solutionOptions = $available->map(fn ($solution) => ['value' => $solution->id, 'label' => $solution->name])->all();

    // With every solution already linked there's nothing to re-point a row at,
    // so the name stays plain text (its ↗ still opens the system) instead of
    // offering an editor whose only option is what it already says.
    $canSwap = $canEdit && filled($solutionOptions);
@endphp

<div id="{{ $domId }}" class="animate-ak-rise mt-5 rounded-card border border-line bg-surface p-6 shadow-card" style="animation-delay: 90ms">
    <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas ({{ $person->solutions->count() }})</h2>

    @if ($person->solutions->isEmpty())
        <p class="mt-2 text-sm text-muted">Nenhum sistema vinculado.</p>
    @else
        <ul class="mt-3 divide-y divide-line">
            @foreach ($person->solutions as $solution)
                <li class="group/row flex items-center justify-between gap-3 py-2.5 text-sm">
                    {{-- The name is click-to-edit like every other datum: it
                         re-points this link at another system, carrying its role
                         over. The system's own page is reached through the ↗
                         (`link`) — the picker offers what isn't linked yet plus
                         this row's own system, so the select opens on it. --}}
                    <x-ui.inline-edit name="solution_id" type="select" :value="$solution->id"
                                      :options="array_merge([['value' => $solution->id, 'label' => $solution->name]], $solutionOptions)"
                                      :nullable="false"
                                      :action="$canSwap ? route('people.solutions.update', [$person, $solution]) : null"
                                      :editable="$canSwap"
                                      :link="route('solutions.show', $solution)" link-label="sistema"
                                      label="Sistema" edit-class="min-w-56" class="min-w-0"
                                      input-class="!font-medium">
                        <span class="font-medium text-ink">{{ $solution->name }}</span>
                    </x-ui.inline-edit>

                    <div class="flex shrink-0 items-center gap-1">
                        {{-- The role is a pivot column, so it gets its own
                             endpoint — same gesture as every other datum here. --}}
                        <x-ui.inline-edit name="role" type="select" :options="$roleOptions" :value="$solution->pivot->role"
                                          :action="$canEdit ? route('people.solutions.update', [$person, $solution]) : null"
                                          :editable="$canEdit" label="Papel" :nullable="false" edit-class="min-w-56"
                                          input-class="!text-xs !font-medium">
                            <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-accent-line">{{ \App\Enums\PersonSolutionRole::from($solution->pivot->role)->label() }}</span>
                        </x-ui.inline-edit>

                        @if ($canEdit)
                            <x-ui.row-remove id="person-system-remove-{{ $solution->id }}"
                                             :action="route('people.solutions.destroy', [$person, $solution])"
                                             confirm='Desvincular "{{ $solution->name }}" desta pessoa?'
                                             label="Desvincular {{ $solution->name }}" />
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit && filled($solutionOptions))
        <x-ui.inline-edit method="POST" :action="route('people.solutions.store', $person)"
                         :fields="[
                             ['name' => 'solution_id', 'type' => 'select', 'options' => $solutionOptions, 'label' => 'Sistema', 'empty' => 'Escolha um sistema', 'class' => 'min-w-0 flex-1'],
                             ['name' => 'role', 'type' => 'select', 'options' => $roleOptions, 'value' => \App\Enums\PersonSolutionRole::Technical->value, 'nullable' => false, 'label' => 'Papel', 'class' => 'w-48 shrink-0'],
                         ]"
                         label="Sistema" edit-class="mt-3 w-full max-w-full" class="mt-3 block">
            <x-ui.add-chip>Vincular sistema</x-ui.add-chip>
        </x-ui.inline-edit>
    @endif
</div>
