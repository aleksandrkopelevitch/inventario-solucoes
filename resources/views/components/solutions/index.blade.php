@use('App\Support\CategoryPalette')
@php
    [$sortForm, $sortUrl] = ['solutions-filter-form', route('solutions.index')];
@endphp
<div id="{{ $domId }}">
    @if ($solutions->isEmpty())
        <x-ui.no-results title="Nenhuma solução encontrada" form-id="solutions-filter-form" :url="route('solutions.index')" />
    @else
        <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b border-line bg-raised/40">
                            <x-ui.sortable-th column="name" :filters="$filters" :form-id="$sortForm" :url="$sortUrl" class="pl-4">Nome</x-ui.sortable-th>
                            <x-ui.sortable-th column="category" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Categoria</x-ui.sortable-th>
                            <x-ui.sortable-th column="status" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Status</x-ui.sortable-th>
                            <x-ui.sortable-th column="environment" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Ambiente</x-ui.sortable-th>
                            <x-ui.sortable-th column="vendor" :filters="$filters" :form-id="$sortForm" :url="$sortUrl">Fornecedor</x-ui.sortable-th>
                            <th scope="col" class="py-2.5 pr-4"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        {{-- Stagger is capped so a 90-item result set doesn't cascade for
                             seconds; it replays gently on each filter/search AJAX swap. --}}
                        @foreach ($solutions as $solution)
                            <tr class="animate-ak-rise transition-colors hover:bg-raised/40"
                                style="animation-delay: {{ min($loop->index, 11) * 25 }}ms">
                                <td class="py-2.5 pl-4 pr-3">
                                    <a href="{{ route('solutions.show', $solution) }}" class="flex min-w-0 items-center gap-3 no-underline">
                                        <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="sm"
                                            :tone="CategoryPalette::tileClass($solution->category)" />
                                        <span class="min-w-0 truncate font-display text-[15px] font-semibold text-ink hover:text-accent">
                                            <x-ui.highlight :text="$solution->name" :term="$filters['search'] ?? null" />
                                        </span>
                                    </a>
                                </td>
                                <td class="px-3 py-2.5">
                                    @if ($solution->category_label)
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ CategoryPalette::chipClass($solution->category) }}">
                                            <x-dynamic-component :component="'heroicon-o-'.CategoryPalette::icon($solution->category)" class="size-3" />
                                            {{ $solution->category_label }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex whitespace-nowrap rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-accent ring-1 ring-accent-line">{{ $solution->status_label }}</span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-muted">
                                    @if ($solution->environment)
                                        {{ $solution->environment_label }}@if ($solution->cloud) · {{ $solution->cloud_label }}@endif
                                    @else
                                        <span class="text-faint">—</span>
                                    @endif
                                </td>
                                <td class="min-w-0 max-w-[14rem] truncate px-3 py-2.5 text-muted">
                                    @if ($solution->vendor)
                                        <x-ui.highlight :text="$solution->vendor->name" :term="$filters['search'] ?? null" />
                                    @else
                                        <span class="text-faint">Sem fornecedor</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pl-3 pr-4 text-right">
                                    @can('update', $solution)
                                        <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('solutions.edit', ['solution' => $solution, 'filter' => $filters]) }}"
                                           class="inline-flex rounded-field p-1.5 text-faint transition-colors hover:bg-raised hover:text-accent" title="Editar">
                                            <x-heroicon-o-pencil-square class="size-4" />
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
