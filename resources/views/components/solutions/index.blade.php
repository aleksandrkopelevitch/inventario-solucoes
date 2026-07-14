<div id="{{ $domId }}">
    @if ($solutions->isEmpty())
        <div class="rounded-card border border-dashed border-line-2 bg-surface p-10 text-center text-sm text-muted">
            <p class="mb-1 font-medium text-ink">Nenhuma solução encontrada</p>
            <p class="mb-4">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear-all="{{ json_encode(['formId' => 'solutions-filter-form', 'url' => route('solutions.index')]) }}"
                class="border border-line-2 !text-accent hover:!bg-accent-soft">
                Limpar filtros
            </x-forms.button>
        </div>
    @else
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($solutions as $solution)
                <div class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                    <div class="flex items-start gap-3">
                        <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="md" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('solutions.show', $solution) }}" class="block truncate font-display text-[17px] font-semibold text-ink hover:text-accent">
                                <x-ui.highlight :text="$solution->name" :term="$filters['search'] ?? null" />
                            </a>
                            <p class="truncate text-xs text-muted">{{ $solution->category_label }}@if ($solution->directorate) · {{ $solution->directorate }}@endif</p>
                        </div>
                        @can('update', $solution)
                            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('solutions.edit', ['solution' => $solution, 'filter' => $filters]) }}"
                               class="shrink-0 rounded-field p-1.5 text-faint transition-colors hover:bg-raised hover:text-accent" title="Editar">
                                <x-heroicon-o-pencil-square class="size-4" />
                            </a>
                        @endcan
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-[11px] font-medium">
                        @if ($solution->environment)
                            <span class="rounded-full bg-lime-soft px-2 py-0.5 text-lime-ink ring-1 ring-lime-line">{{ $solution->environment_label }}@if ($solution->cloud) · {{ $solution->cloud_label }}@endif</span>
                        @endif
                        @if ($solution->contract_status)
                            <span class="rounded-full bg-hot-soft px-2 py-0.5 text-hot ring-1 ring-hot-line">{{ $solution->contract_status_label }}</span>
                        @endif
                        <span class="rounded-full bg-accent-soft px-2 py-0.5 text-accent ring-1 ring-accent-line">{{ $solution->status_label }}</span>
                    </div>

                    <div class="mt-auto flex items-center justify-between border-t border-line pt-2.5 text-xs text-muted">
                        <span class="flex items-center gap-3">
                            <span title="Fornecedor">
                                @if ($solution->vendor)
                                    <x-ui.highlight :text="$solution->vendor->name" :term="$filters['search'] ?? null" />
                                @else
                                    Sem fornecedor
                                @endif
                            </span>
                        </span>
                        <span class="flex items-center gap-2.5">
                            <span class="flex items-center gap-1" title="Integrações entrada / saída">
                                <x-heroicon-o-arrow-down-left class="size-3.5 text-accent" />{{ $solution->active_in }}
                                <x-heroicon-o-arrow-up-right class="size-3.5 text-accent" />{{ $solution->active_out }}
                            </span>
                            @php ($hasDocs = (bool) $solution->has_docs)
                            <span @class([
                                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                'bg-accent-soft text-accent' => $hasDocs,
                                'bg-raised text-muted' => ! $hasDocs,
                            ]) title="Documentação">
                                {{ $hasDocs ? 'Documentado' : 'Sem doc' }}
                            </span>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
