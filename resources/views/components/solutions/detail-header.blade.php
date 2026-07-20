@use('App\Support\CategoryPalette')
<div id="{{ $domId }}">
    <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
        {{-- Identity strip: logo, name, vendor, description and action --}}
        <div class="relative flex flex-wrap items-start gap-4 p-6">
            {{-- Subtle green tint at the top of the card (Leo identity) --}}
            <div class="pointer-events-none absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-accent-soft/60 to-transparent"></div>

            <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="lg" class="relative shadow-sm"
                :tone="CategoryPalette::tileClass($solution->category)" />

            <div class="relative min-w-0 flex-1">
                <h1 class="font-display text-[28px] font-semibold leading-tight text-ink">{{ $solution->name }}</h1>

                @if ($solution->vendor)
                    <a href="{{ route('companies.show', $solution->vendor) }}"
                       class="mt-2 inline-flex items-center gap-2 rounded-full border border-line bg-surface/70 py-0.5 pl-0.5 pr-3 text-sm text-muted backdrop-blur transition hover:border-line-2 hover:text-accent">
                        <x-ui.logo :name="$solution->vendor->name" :src="$solution->vendor->logo_path" size="sm" />
                        <span class="min-w-0 truncate">{{ $solution->vendor->name }}</span>
                    </a>
                @endif

                @if ($solution->description)
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-body">{{ $solution->description }}</p>
                @endif
            </div>

            @can('update', $solution)
                <x-forms.button href="#" variant="glass" class="shrink-0"
                    data-ak-panel-open data-ak-panel-url="{{ route('solutions.edit', $solution) }}">
                    <x-heroicon-o-pencil-square class="size-4" /> Editar
                </x-forms.button>
            @endcan
        </div>

        {{-- Attribute sheet — each value shown with its dimension's LABEL
             (Category, Status, …), no longer loose pills without context.
             Always all 8, even blank ("Não informado" instead of disappearing
             from the grid — see `Solutions\DetailHeader::render()`). Editable
             in place: each attribute is a `<select>` that auto-persists on
             `change` (`solution-attributes.js`), without needing to open the
             full edit panel. --}}
        @if ($facts->isNotEmpty())
            @php
                $factTones = [
                    'anchor'  => 'bg-accent text-white',
                    'green'   => 'bg-accent-soft text-accent ring-1 ring-accent-line',
                    'lime'    => 'bg-lime-soft text-lime-ink ring-1 ring-lime-line',
                    'amber'   => 'bg-hot-soft text-hot ring-1 ring-hot-line',
                    'crit'    => 'bg-crit-soft text-crit ring-1 ring-crit-line',
                    'neutral' => 'bg-raised text-body ring-1 ring-line-2',
                ];
                // Same tone classes as above, but each utility marked `!`
                // important — only needed on the editable `<select>`, to
                // beat `<x-forms.select>`'s default `bg-surface`/`text-ink`/
                // `rounded-field` (see resources/views/components/forms/select.blade.php).
                // The viewer's `<span>` doesn't need this (no component to
                // beat), hence the two maps.
                $important = fn (string $classes) => '!' . str_replace(' ', ' !', trim($classes));
                // Category is colored by its family (CategoryPalette), not a
                // fixed tone — resolve per value; everything else uses the map.
                $toneClasses = fn (array $fact) => $fact['tone'] === 'category'
                    ? CategoryPalette::chipClass($fact['value'])
                    : ($factTones[$fact['tone']] ?? $factTones['neutral']);
                $canEditAttributes = \Illuminate\Support\Facades\Gate::allows('update', $solution);
            @endphp
            <dl @if ($canEditAttributes) data-solution-attributes data-action="{{ route('solutions.attributes.update', $solution) }}" @endif
                class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($facts as $fact)
                    <div class="bg-surface px-5 py-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $fact['label'] }}</dt>
                        <dd class="mt-1.5">
                            @if (! $canEditAttributes)
                                {{-- Viewer: same presentation as always, no select. --}}
                                @if ($fact['tone'] === 'plain')
                                    <span class="text-sm font-medium {{ filled($fact['value']) ? 'text-ink' : 'text-faint italic' }}">{{ $fact['displayLabel'] ?: 'Não informado' }}</span>
                                @elseif (blank($fact['value']))
                                    <span class="inline-flex items-center rounded-md border border-dashed border-line-2 px-2.5 py-1 text-xs font-medium text-faint">Não informado</span>
                                @else
                                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $toneClasses($fact) }}">{{ $fact['displayLabel'] }}</span>
                                @endif
                            @elseif ($fact['tone'] === 'plain')
                                <x-forms.select name="{{ $fact['group'] }}" title="Editar {{ $fact['label'] }}"
                                    data-ak-solution-attribute data-ak-attribute-select="{{ $fact['group'] }}"
                                    data-ak-attribute-options-url="{{ route('attribute-options.options', $fact['group']) }}"
                                    class="!h-8 !py-0 !text-sm">
                                    <option value="" @selected(blank($fact['value']))>Não informado</option>
                                    @foreach ($attributeOptions[$fact['group']] as $option)
                                        <option value="{{ $option->value }}" @selected($fact['value'] === $option->value)>{{ $option->label }}</option>
                                    @endforeach
                                </x-forms.select>
                            @else
                                <x-forms.select name="{{ $fact['group'] }}" title="Editar {{ $fact['label'] }}"
                                    data-ak-solution-attribute data-ak-attribute-select="{{ $fact['group'] }}"
                                    data-ak-attribute-options-url="{{ route('attribute-options.options', $fact['group']) }}"
                                    class="!h-[26px] !rounded-md !py-0 !pl-2.5 !pr-6 !text-xs !font-semibold {{ blank($fact['value']) ? '!border !border-dashed !border-line-2 !bg-transparent !text-faint' : '!border-0 ' . $important($toneClasses($fact)) }}">
                                    @if ($fact['nullable'])
                                        <option value="" @selected(blank($fact['value']))>Não informado</option>
                                    @endif
                                    @foreach ($attributeOptions[$fact['group']] as $option)
                                        <option value="{{ $option->value }}" @selected($fact['value'] === $option->value)>{{ $option->label }}</option>
                                    @endforeach
                                </x-forms.select>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- Owners by role --}}
        <div class="grid gap-x-6 gap-y-5 border-t border-line p-6 sm:grid-cols-3">
            @foreach (['Owner técnico' => $techOwners, 'Owner de negócio' => $businessOwners, 'Contato do fornecedor' => $vendorContacts] as $roleLabel => $group)
                <div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $roleLabel }}</div>
                    @forelse ($group as $person)
                        <a href="{{ route('people.show', $person) }}" class="mt-2 flex items-center gap-2.5 text-sm text-ink hover:text-accent">
                            <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="sm" />
                            <span class="min-w-0 truncate font-medium">{{ $person->name }}</span>
                        </a>
                    @empty
                        <p class="mt-2 flex items-center gap-2.5 text-sm text-faint">
                            <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-line-2">—</span>
                            <span>Não atribuído</span>
                        </p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>

    @if ($solution->support_operation_note)
        <div class="mt-5 flex gap-3 rounded-card border border-crit-line bg-crit-soft p-4">
            <x-heroicon-o-exclamation-triangle class="size-5 shrink-0 text-crit" />
            <div>
                <b class="text-sm text-ink">Suporte × operação</b>
                <p class="mt-0.5 text-sm text-muted">{{ $solution->support_operation_note }}</p>
            </div>
        </div>
    @endif
</div>
