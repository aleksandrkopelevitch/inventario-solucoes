<div id="{{ $domId }}">
    @if ($companies->isEmpty())
        <div class="animate-ak-pop flex flex-col items-center rounded-card border border-dashed border-line-2 bg-surface p-12 text-center text-sm text-muted">
            <span class="mb-4 flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-lime-soft to-accent-soft text-accent shadow-sm ring-1 ring-accent-line">
                <x-heroicon-o-magnifying-glass class="size-6" />
            </span>
            <p class="mb-1 font-semibold text-ink">Nenhuma empresa encontrada</p>
            <p class="mb-4 max-w-xs">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear-all="{{ json_encode(['formId' => 'companies-filter-form', 'url' => route('companies.index')]) }}"
                class="border border-line-2 !text-accent hover:!bg-accent-soft">
                Limpar filtros
            </x-forms.button>
        </div>
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
