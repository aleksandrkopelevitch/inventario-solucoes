<div id="{{ $domId }}">
    @if ($companies->isEmpty())
        <x-ui.no-results title="Nenhuma empresa encontrada" form-id="companies-filter-form" :url="route('companies.index')" />
    @else
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($companies as $company)
                <div class="animate-ak-rise flex items-start gap-3 rounded-card border border-line bg-surface p-4 shadow-card transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-accent-line hover:shadow-[0_10px_28px_-10px_rgba(20,58,34,0.22)]"
                     style="animation-delay: {{ min($loop->index, 11) * 35 }}ms">
                    <x-ui.logo :name="$company->name" :src="$company->logo_path" size="md" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('companies.show', $company) }}" class="block truncate font-display text-[16px] font-semibold text-ink hover:text-accent">
                            <x-ui.highlight :text="$company->name" :term="$filters['search'] ?? null" />
                        </a>
                        <p class="truncate text-xs text-muted">{{ $company->kind->label() }}</p>
                        <p class="mt-1.5 text-[11px] text-faint">
                            {{ $company->people_count }} {{ \Illuminate\Support\Str::plural('pessoa', $company->people_count) }}
                            · {{ $company->provided_solutions_count }} {{ \Illuminate\Support\Str::plural('sistema', $company->provided_solutions_count) }}
                        </p>
                    </div>
                    @can('update', $company)
                        <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('companies.edit', ['company' => $company, 'filter' => $filters]) }}"
                           class="shrink-0 rounded-field p-1.5 text-faint transition-colors hover:bg-raised hover:text-accent" title="Editar">
                            <x-heroicon-o-pencil-square class="size-4" />
                        </a>
                    @endcan
                </div>
            @endforeach
        </div>
    @endif
</div>
