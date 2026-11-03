<div id="{{ $domId }}">
    @if ($people->isEmpty())
        <x-ui.no-results title="Nenhuma pessoa encontrada" form-id="people-filter-form" :url="route('people.index')" />
    @else
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($people as $person)
                <div class="animate-ak-rise flex items-start gap-3 rounded-card border border-line bg-surface p-4 shadow-card transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-accent-line hover:shadow-[0_10px_28px_-10px_rgba(20,58,34,0.22)]"
                     style="animation-delay: {{ min($loop->index, 11) * 35 }}ms">
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
