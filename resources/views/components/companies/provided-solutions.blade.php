@php
    // The current vendor rides along in the label so re-assigning a system is
    // never done blind (see `Companies\ProvidedSolutions::render()`).
    $solutionOptions = $available->map(fn ($solution) => [
        'value' => $solution->id,
        'label' => $solution->vendor ? $solution->name . ' — ' . $solution->vendor->name : $solution->name,
    ])->all();
@endphp

<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-6 shadow-card">
    <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas fornecidos ({{ $company->providedSolutions->count() }})</h2>

    {{-- Creator before the list, on its own line — same reasoning as the people
         card next to it. --}}
    @if ($canEdit && filled($solutionOptions))
        <x-ui.inline-edit method="POST" :action="route('companies.solutions.store', $company)"
                         name="solution_id" type="select" :options="$solutionOptions"
                         label="Sistema" empty="Escolha um sistema"
                         edit-class="mt-3 w-full max-w-full" class="mt-3 block">
            <x-ui.add-chip>Adicionar sistema</x-ui.add-chip>
        </x-ui.inline-edit>
    @endif

    @if ($company->providedSolutions->isEmpty())
        <p class="mt-2 text-sm text-muted">Nenhum sistema fornecido.</p>
    @else
        <ul class="mt-3 divide-y divide-line">
            @foreach ($company->providedSolutions as $solution)
                <li class="group/row flex items-center justify-between gap-3 py-2.5 text-sm">
                    {{-- No editor over the name, so it stays a full link — the
                         ↗ split only settles a navigate-vs-edit conflict, and
                         there's none here. The category badge is read-only too:
                         it's an attribute, edited on the solution's own page. --}}
                    <a href="{{ route('solutions.show', $solution) }}" class="min-w-0 flex-1 truncate font-medium text-ink hover:text-accent">{{ $solution->name }}</a>

                    <div class="flex shrink-0 items-center gap-1">
                        <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent ring-1 ring-accent-line">{{ $solution->category_label }}</span>

                        @if ($canEdit)
                            <form id="company-solution-remove-{{ $solution->id }}" class="hidden">
                                @csrf
                                @method('DELETE')
                            </form>
                            <x-forms.button type="button" variant="ghost"
                                            class="!size-6 !rounded-full !p-0 text-faint opacity-0 transition-opacity group-hover/row:opacity-100 hover:!bg-crit-soft hover:!text-crit focus-visible:opacity-100"
                                            data-ak-ajax="company-solution-remove-{{ $solution->id }}"
                                            data-ak-action="{{ route('companies.solutions.destroy', [$company, $solution]) }}"
                                            data-ak-confirm="Desvincular &quot;{{ $solution->name }}&quot; desta empresa? O sistema continua cadastrado, apenas sem fornecedor."
                                            aria-label="Desvincular {{ $solution->name }}" title="Desvincular">
                                <x-heroicon-o-x-mark class="size-3.5" />
                            </x-forms.button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
