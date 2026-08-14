@use('App\Support\CategoryPalette')
@php
    // Punctual editing lives on this header (`x-ui.inline-edit`): the
    // solution's own columns (logo, name, vendor, description, support note)
    // are click-to-edit for whoever can update it, and byte-for-byte the old
    // read-only markup for everyone else. The 8 attribute badges below have
    // their own, older mechanism (`solution-attributes.js`, auto-save on
    // `change`) and their own endpoint. The "Editar" panel stays for what a
    // single gesture can't express (and for editing several things at once).
    $fieldAction = $canEdit ? route('solutions.field.update', $solution) : null;

    $vendorOptions = $companies->map(fn ($company) => [
        'value' => $company->id,
        'label' => $company->name,
    ])->all();

    $logoUrl = $solution->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($solution->logo_path)
        : null;

    // Owners grid at the bottom of the card. The person's own company rides
    // along in the label — two "Ana Silva" in a 108-person picker are otherwise
    // indistinguishable.
    $ownerOptions = $availableOwners->map(fn ($person) => [
        'value' => $person->id,
        'label' => $person->company ? $person->name . ' — ' . $person->company->name : $person->name,
    ])->all();

    $roleOptions = collect($roles)->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()])->all();
@endphp
<div id="{{ $domId }}">
    <div class="overflow-hidden rounded-bento border border-line bg-surface shadow-card">
        {{-- Identity strip: logo, name, vendor, description and action.
             Redesign 2026-08-04 (see [[radiant-protocol-redesign]]): the old
             green tint became the full gradient "glow" signature — this div
             is the FIRST child of the outer overflow-hidden/rounded-bento
             container, so its background is already clipped to the rounded
             top corners without needing its own radius. --}}
        {{-- `--ie-wash` overrides the hover wash of every click-to-edit datum
             on this strip — same reasoning as the other two headers (app.css). --}}
        <div class="relative flex flex-wrap items-start gap-4 p-6"
             style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%); --ie-wash: rgba(255, 255, 255, .5)">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
                 style="background: var(--gradient-hero-seam)"></div>

            {{-- `upload-id` is not decoration: `avatar-upload.js` finds a tile's
                 input by element id, and the side panel's solution form renders
                 its own `logo` upload — identical default ids would have the two
                 fighting over the same picker while the panel is open. --}}
            {{-- `w-auto`: the tile matches the logo it replaces, so the editor
                 has no reason to be wider and shove the identity column. --}}
            <x-ui.inline-edit name="logo" type="file" :image-value="$logoUrl" upload-id="solution-logo-inline"
                              :action="$fieldAction" :editable="$canEdit"
                              label="Logo" edit-class="w-auto" class="relative">
                <x-ui.logo :name="$solution->name" :src="$solution->logo_path" size="lg" class="shadow-sm"
                    :tone="CategoryPalette::tileClass($solution->category)" />
            </x-ui.inline-edit>

            <div class="relative min-w-0 flex-1">
                {{-- `input-class` mirrors the h1 below it: read as a 32px
                     display heading, retyped as one. --}}
                <x-ui.inline-edit name="name" :value="$solution->name" :action="$fieldAction" :editable="$canEdit"
                                  label="Nome" edit-class="min-w-72 max-w-2xl"
                                  input-class="!font-display !text-[32px] !font-bold !leading-tight !tracking-tight !text-[color:var(--color-glow-ink)]">
                    <h1 class="font-display text-[32px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">{{ $solution->name }}</h1>
                </x-ui.inline-edit>

                {{-- Vendor chip: a rounded-rect tag matching the squared logo
                     (company logos are rounded-rects app-wide; only people are
                     circles). Was `rounded-full`, which fought the square logo.
                     The chip is click-to-edit and the ↗ inside it opens the
                     company's page — the app-wide rule for a datum that also
                     links somewhere, which is why the chip is no longer an
                     `<a>` itself. --}}
                <x-ui.inline-edit name="vendor_company_id" type="select" :options="$vendorOptions"
                                  :value="$solution->vendor_company_id"
                                  :action="$fieldAction" :editable="$canEdit"
                                  :link="$solution->vendor ? route('companies.show', $solution->vendor) : null"
                                  link-label="fornecedor"
                                  label="Fornecedor" empty="Definir fornecedor"
                                  edit-class="min-w-64 max-w-sm" class="mt-2">
                    @if ($solution->vendor)
                        <span class="inline-flex items-center gap-2 rounded-lg border border-white/40 bg-white/50 py-1 pl-1 pr-3 text-sm text-[color:var(--color-glow-ink)]/75 backdrop-blur">
                            <x-ui.logo :name="$solution->vendor->name" :src="$solution->vendor->logo_path" size="sm" />
                            <span class="min-w-0 truncate">{{ $solution->vendor->name }}</span>
                        </span>
                    @endif
                </x-ui.inline-edit>

                <x-ui.inline-edit name="description" type="textarea" :value="$solution->description" :rows="4"
                                  :action="$fieldAction" :editable="$canEdit"
                                  label="Descrição" empty="Adicionar descrição"
                                  edit-class="w-full max-w-2xl" class="mt-3 block">
                    {{-- Markdown on the reading side, plain textarea on the
                         editing side — see x-ui.markdown. The color/size stay
                         here, on the wrapper: `.ak-rich-text` sets neither, so
                         the description keeps reading as part of the gradient
                         strip instead of as a document. --}}
                    <x-ui.markdown :text="$solution->description"
                                   class="max-w-2xl text-sm leading-relaxed text-[color:var(--color-glow-ink)]/80" />
                </x-ui.inline-edit>
            </div>

            @can('update', $solution)
                <x-forms.button href="#" variant="glass" class="relative shrink-0"
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
            @endphp
            <dl @if ($canEdit) data-solution-attributes data-action="{{ route('solutions.attributes.update', $solution) }}" @endif
                class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($facts as $fact)
                    <div class="bg-surface px-5 py-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $fact['label'] }}</dt>
                        <dd class="mt-1.5">
                            @if (! $canEdit)
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

        {{-- Owners by role — the `person_solution` pivot, linked/unlinked right
             here (the mirror of the person page's "Sistemas" card). The role is
             which COLUMN a person sits in, so the creator asks for both at once;
             re-roling stays on the person's page, where the role is a badge of
             its own. Names keep being plain links: no editor competes with them
             here, so there's nothing for the ↗ split to settle. --}}
        <div class="border-t border-line p-6">
            <div class="grid gap-x-6 gap-y-5 sm:grid-cols-3">
                @foreach (['Owner técnico' => $techOwners, 'Owner de negócio' => $businessOwners, 'Contato do fornecedor' => $vendorContacts] as $roleLabel => $group)
                    <div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $roleLabel }}</div>
                        @forelse ($group as $person)
                            <div class="group/row mt-2 flex items-center gap-1">
                                <a href="{{ route('people.show', $person) }}" class="flex min-w-0 flex-1 items-center gap-2.5 text-sm text-ink hover:text-accent">
                                    <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="sm" />
                                    <span class="min-w-0 truncate font-medium">{{ $person->name }}</span>
                                </a>

                                @if ($canEdit)
                                    <form id="solution-person-remove-{{ $person->id }}" class="hidden">
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                    <x-forms.button type="button" variant="ghost"
                                                    class="!size-6 !rounded-full !p-0 shrink-0 text-faint opacity-0 transition-opacity group-hover/row:opacity-100 hover:!bg-crit-soft hover:!text-crit focus-visible:opacity-100"
                                                    data-ak-ajax="solution-person-remove-{{ $person->id }}"
                                                    data-ak-action="{{ route('solutions.people.destroy', [$solution, $person]) }}"
                                                    data-ak-confirm="Desvincular &quot;{{ $person->name }}&quot; deste sistema?"
                                                    aria-label="Desvincular {{ $person->name }}" title="Desvincular">
                                        <x-heroicon-o-x-mark class="size-3.5" />
                                    </x-forms.button>
                                @endif
                            </div>
                        @empty
                            <p class="mt-2 flex items-center gap-2.5 text-sm text-faint">
                                <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-line-2">—</span>
                                <span>Não atribuído</span>
                            </p>
                        @endforelse
                    </div>
                @endforeach
            </div>

            @if ($canEdit && filled($ownerOptions))
                <x-ui.inline-edit method="POST" :action="route('solutions.people.store', $solution)"
                                 :fields="[
                                     ['name' => 'person_id', 'type' => 'select', 'options' => $ownerOptions, 'label' => 'Pessoa', 'empty' => 'Escolha uma pessoa', 'class' => 'min-w-0 flex-1'],
                                     ['name' => 'role', 'type' => 'select', 'options' => $roleOptions, 'value' => \App\Enums\PersonSolutionRole::Technical->value, 'nullable' => false, 'label' => 'Papel', 'class' => 'w-48 shrink-0'],
                                 ]"
                                 label="Pessoa" edit-class="mt-4 w-full max-w-full" class="mt-4 block">
                    <x-ui.add-chip>Vincular pessoa</x-ui.add-chip>
                </x-ui.inline-edit>
            @endif
        </div>
    </div>

    {{-- The note is a WARNING, so an empty one can't wear the same red box: with
         nothing written yet (and the right to write it) the block degrades to a
         discreet dashed placeholder, and only turns into the alert once there's
         something to warn about. --}}
    @if ($solution->support_operation_note || $canEdit)
        @php $hasNote = filled($solution->support_operation_note); @endphp
        <div @class([
                 'mt-5 flex gap-3 rounded-card p-4',
                 'border border-crit-line bg-crit-soft' => $hasNote,
                 'border border-dashed border-line-2 bg-surface' => ! $hasNote,
             ])>
            <x-heroicon-o-exclamation-triangle @class(['size-5 shrink-0', 'text-crit' => $hasNote, 'text-faint' => ! $hasNote]) />
            <div class="min-w-0 flex-1">
                <b @class(['text-sm', 'text-ink' => $hasNote, 'text-muted' => ! $hasNote])>Suporte × operação</b>
                <x-ui.inline-edit name="support_operation_note" type="textarea"
                                  :value="$solution->support_operation_note" :rows="3"
                                  :action="$fieldAction" :editable="$canEdit"
                                  label="Nota de suporte × operação" empty="Adicionar nota"
                                  edit-class="w-full max-w-full" class="mt-0.5 block">
                    {{-- Same treatment as the other free-text fields: this is
                         where "não reiniciar o serviço X" lists get written,
                         and a list is exactly what Markdown is for. --}}
                    <x-ui.markdown :text="$solution->support_operation_note" class="text-sm leading-relaxed text-muted" />
                </x-ui.inline-edit>
            </div>
        </div>
    @endif
</div>
