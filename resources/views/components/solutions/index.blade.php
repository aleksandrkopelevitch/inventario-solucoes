@use('App\Support\CategoryPalette')
<div id="{{ $domId }}">
    @if ($solutions->isEmpty())
        <div class="animate-ak-pop flex flex-col items-center rounded-card border border-dashed border-line-2 bg-surface p-12 text-center text-sm text-muted">
            <span class="mb-4 flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-lime-soft to-accent-soft text-accent shadow-sm ring-1 ring-accent-line">
                <x-heroicon-o-magnifying-glass class="size-6" />
            </span>
            <p class="mb-1 font-semibold text-ink">Nenhuma solução encontrada</p>
            <p class="mb-4 max-w-xs">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <x-forms.button type="button" variant="ghost"
                data-ak-filters-clear-all="{{ json_encode(['formId' => 'solutions-filter-form', 'url' => route('solutions.index')]) }}"
                class="border border-line-2 !text-accent hover:!bg-accent-soft">
                Limpar filtros
            </x-forms.button>
        </div>
    @else
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($solutions as $solution)
                {{-- Stagger is capped so a 90-item result set doesn't cascade for
                     seconds; it replays gently on each filter/search AJAX swap. --}}
                <div class="animate-ak-rise flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-card transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-accent-line hover:shadow-[0_10px_28px_-10px_rgba(20,58,34,0.22)]"
                     style="animation-delay: {{ min($loop->index, 11) * 35 }}ms">
                    <div class="flex items-start gap-3">
                        <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="md"
                            :tone="CategoryPalette::tileClass($solution->category)" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('solutions.show', $solution) }}" class="block truncate font-display text-[17px] font-semibold text-ink hover:text-accent">
                                <x-ui.highlight :text="$solution->name" :term="$filters['search'] ?? null" />
                            </a>
                            <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                @if ($solution->category_label)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ CategoryPalette::chipClass($solution->category) }}">
                                        <x-dynamic-component :component="'heroicon-o-'.CategoryPalette::icon($solution->category)" class="size-3" />
                                        {{ $solution->category_label }}
                                    </span>
                                @endif
                                @if ($solution->directorate)
                                    <span class="truncate text-xs text-muted">{{ $solution->directorate }}</span>
                                @endif
                            </div>
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
