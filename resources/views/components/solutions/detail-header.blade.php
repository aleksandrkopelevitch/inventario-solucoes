@use('App\Support\CategoryPalette')
@php
    // Punctual editing lives on this header (`x-ui.inline-edit`): every datum
    // on it — logo, name, vendor, description, the 8 attribute badges, the
    // owners, the notes — is click-to-edit for whoever can update it, and
    // byte-for-byte the old read-only markup for everyone else. The "Editar"
    // panel stays for what a single gesture can't express (and for editing
    // several things at once).
    //
    // Two endpoints, one gesture: the solution's own COLUMNS go to
    // `solutions.field.update`, the 8 attributes to `solutions.attributes.update`
    // (they're `attribute_options` values, validated per group). Until
    // 2026-08-15 the badges were bare `<select>`s that auto-saved on `change`
    // — the only thing on a read-only page that looked like a form before you
    // asked to edit anything.
    $fieldAction = $canEdit ? route('solutions.field.update', $solution) : null;
    $attributeAction = $canEdit ? route('solutions.attributes.update', $solution) : null;

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
             from the grid — see `Solutions\DetailHeader::render()`).

             A VALUE, not a control: the badge is the same tone-coloured chip
             the viewer sees, and only becomes a `<select>` once you ask to edit
             it (double click, or the pencil) — exactly like every other datum
             on this page. Before 2026-08-15 these eight were permanently open
             `<select>`s that saved on `change`, which made a page meant to be
             read look like a form and gave the header two different editing
             gestures side by side. --}}
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
                // Category is colored by its family (CategoryPalette), not a
                // fixed tone — resolve per value; everything else uses the map.
                $toneClasses = fn (array $fact) => $fact['tone'] === 'category'
                    ? CategoryPalette::chipClass($fact['value'])
                    : ($factTones[$fact['tone']] ?? $factTones['neutral']);
                // `attribute_options` rows → the `[['value','label'], …]` shape
                // x-ui.inline-edit's select expects.
                $optionsFor = fn (string $group) => $attributeOptions[$group]
                    ->map(fn ($option) => ['value' => $option->value, 'label' => $option->label])
                    ->all();
            @endphp
            <dl class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($facts as $fact)
                    <div class="bg-surface px-5 py-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $fact['label'] }}</dt>
                        <dd class="mt-1.5">
                            {{-- `input-class` keeps the editor typed like the
                                 chip it replaces (the plain-text Diretoria
                                 keeps the app's default `text-sm`), so opening
                                 one doesn't resize the row it sits in. --}}
                            <x-ui.inline-edit :name="$fact['group']" type="select"
                                              :options="$optionsFor($fact['group'])"
                                              :value="$fact['value']" :nullable="$fact['nullable']"
                                              :action="$attributeAction" :editable="$canEdit"
                                              :label="$fact['label']" edit-class="min-w-40"
                                              :input-class="$fact['tone'] === 'plain' ? null : '!text-xs !font-semibold'">
                                @if ($fact['tone'] === 'plain')
                                    <span class="text-sm font-medium {{ filled($fact['value']) ? 'text-ink' : 'text-faint italic' }}">{{ $fact['displayLabel'] ?: 'Não informado' }}</span>
                                @elseif (blank($fact['value']))
                                    <span class="inline-flex items-center rounded-md border border-dashed border-line-2 px-2.5 py-1 text-xs font-medium text-faint">Não informado</span>
                                @else
                                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $toneClasses($fact) }}">{{ $fact['displayLabel'] }}</span>
                                @endif
                            </x-ui.inline-edit>
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- Owners by role — the `person_solution` pivot, linked / re-pointed /
             unlinked right here (the mirror of the person page's "Sistemas"
             card). The role is which COLUMN a person sits in, so the creator
             asks for both at once; re-roling stays on the person's page, where
             the role is a badge of its own.

             Each name is click-to-edit like every other datum here — it swaps
             WHO holds this role, carrying the link's role over — so the person's
             own page moved to the ↗ beside it (the app-wide split: the words
             belong to the editor, the icon travels). With everyone already
             linked there's nothing left to swap to, so the picker steps aside
             and the name goes back to being plain text with its ↗. --}}
        @php $canSwapOwner = $canEdit && filled($ownerOptions); @endphp
        <div class="border-t border-line p-6">
            <div class="grid gap-x-6 gap-y-5 sm:grid-cols-3">
                @foreach (['Owner técnico' => $techOwners, 'Owner de negócio' => $businessOwners, 'Contato do fornecedor' => $vendorContacts] as $roleLabel => $group)
                    <div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted">{{ $roleLabel }}</div>
                        @forelse ($group as $person)
                            <div class="group/row mt-2 flex items-center gap-1">
                                {{-- The picker opens on this row's own person,
                                     so the list reads as "who else could this
                                     be" rather than as an empty field. --}}
                                <x-ui.inline-edit name="person_id" type="select" :value="$person->id"
                                                  :options="array_merge([['value' => $person->id, 'label' => $person->name]], $ownerOptions)"
                                                  :nullable="false"
                                                  :action="$canSwapOwner ? route('solutions.people.update', [$solution, $person]) : null"
                                                  :editable="$canSwapOwner"
                                                  :link="route('people.show', $person)" link-label="pessoa"
                                                  :label="$roleLabel" edit-class="min-w-56" class="min-w-0 flex-1">
                                    <span class="flex min-w-0 items-center gap-2.5 text-sm text-ink">
                                        <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="sm" />
                                        <span class="min-w-0 truncate font-medium">{{ $person->name }}</span>
                                    </span>
                                </x-ui.inline-edit>

                                @if ($canEdit)
                                    <x-ui.row-remove id="solution-person-remove-{{ $person->id }}"
                                                     :action="route('solutions.people.destroy', [$solution, $person])"
                                                     confirm='Desvincular "{{ $person->name }}" deste sistema?'
                                                     label="Desvincular {{ $person->name }}" />
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

    {{-- Free notes about the solution (it was framed as a "Suporte × operação"
         WARNING and wore a red alert box, which overstated most of what
         actually gets written here). It's a post-it now: the lightest warm
         tint in the palette (`hot-soft`/`hot-line`, the amber family), an
         upturned-corner note icon, and no state swap — an empty one is the
         same note with an invitation to write on it. Only the value inside
         changes register, which is the whole promise of this page.

         `--ie-wash` is overridden for the same reason the gradient strip above
         overrides it: the app's default ink tint reads as a grey smudge over a
         warm ground. --}}
    @if ($solution->support_operation_note || $canEdit)
        <div class="mt-5 flex gap-3 rounded-card border border-hot-line bg-hot-soft p-4"
             style="--ie-wash: rgba(255, 255, 255, .55)">
            <x-heroicon-o-document-text class="size-5 shrink-0 text-hot" />
            <div class="min-w-0 flex-1">
                <b class="text-sm text-ink">Anotações</b>
                <x-ui.inline-edit name="support_operation_note" type="textarea"
                                  :value="$solution->support_operation_note" :rows="3"
                                  :action="$fieldAction" :editable="$canEdit"
                                  label="Anotações" empty="Adicionar anotação"
                                  edit-class="w-full max-w-full" class="mt-0.5 block">
                    {{-- Same treatment as the other free-text fields: this is
                         where "não reiniciar o serviço X" lists get written,
                         and a list is exactly what Markdown is for. --}}
                    <x-ui.markdown :text="$solution->support_operation_note" class="text-sm leading-relaxed text-body" />
                </x-ui.inline-edit>
            </div>
        </div>
    @endif
</div>
