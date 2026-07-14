<div id="{{ $domId }}">
    @if ($people->isEmpty())
        <div class="rounded-card border border-dashed border-line-2 bg-surface p-10 text-center text-sm text-muted">
            <p class="mb-1 font-medium text-ink">Nenhuma pessoa encontrada</p>
            <p class="mb-4">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear-all="{{ json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')]) }}"
                class="border border-line-2 !text-accent hover:!bg-accent-soft">
                Limpar filtros
            </x-forms.button>
        </div>
    @else
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($people as $person)
                <div class="flex items-start gap-3 rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                    <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="lg" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('people.show', $person) }}" class="block truncate font-display text-[16px] font-semibold text-ink hover:text-accent">
                            <x-ui.highlight :text="$person->name" :term="$filters['search'] ?? null" />
                        </a>
                        @if ($person->job_title)<p class="truncate text-xs text-muted">{{ $person->job_title }}</p>@endif
                        @if ($person->company)
                            <a href="{{ route('companies.show', $person->company) }}" class="mt-0.5 block truncate text-xs text-accent hover:underline">
                                <x-ui.highlight :text="$person->company->name" :term="$filters['search'] ?? null" />
                            </a>
                        @endif
                        <p class="mt-1.5 text-[11px] text-faint">{{ $person->solutions_count }} {{ \Illuminate\Support\Str::plural('sistema', $person->solutions_count) }}</p>
                    </div>
                    @can('update', $person)
                        <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.edit', ['person' => $person, 'filter' => $filters]) }}"
                           class="shrink-0 rounded-field p-1.5 text-faint transition-colors hover:bg-raised hover:text-accent" title="Editar">
                            <x-heroicon-o-pencil-square class="size-4" />
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif
</div>
