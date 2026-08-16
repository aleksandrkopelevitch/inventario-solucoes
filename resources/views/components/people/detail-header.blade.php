@php
    // Punctual editing lives on this header (`x-ui.inline-edit`): every
    // dynamic datum is click-to-edit for whoever can update the person, and
    // byte-for-byte the old read-only markup for everyone else. The "Editar"
    // panel stays for what a single gesture can't express (and for editing
    // several things at once).
    $fieldAction = $canEdit ? route('people.field.update', $person) : null;

    $companyOptions = $companies->map(fn ($company) => [
        'value' => $company->id,
        'label' => $company->name,
    ])->all();

    $photoUrl = $person->photo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($person->photo_path)
        : null;

    // The strip mixes two storages behind one identical look: `email`/`phone`
    // are columns on the person, the rest are `Person::contacts()` rows — so
    // each item carries the endpoint that owns it, and only the rows can be
    // deleted (a column is "removed" by emptying it, which its own editor
    // already does).
    $contactFields = collect([
        ['label' => 'E-mail', 'name' => 'email', 'type' => 'email', 'value' => $person->email, 'action' => $fieldAction, 'removeUrl' => null, 'removeId' => null],
        ['label' => 'Telefone', 'name' => 'phone', 'type' => 'tel', 'value' => $person->phone, 'action' => $fieldAction, 'removeUrl' => null, 'removeId' => null],
    ])->concat($person->contacts->map(fn ($contact) => [
        'label'     => $contact->type->label(),
        'name'      => 'value',
        'type'      => 'text',
        'value'     => $contact->value,
        'action'    => $canEdit ? route('people.contacts.update', [$person, $contact]) : null,
        'removeUrl' => $canEdit ? route('people.contacts.destroy', [$person, $contact]) : null,
        'removeId'  => 'person-contact-remove-' . $contact->id,
    ]))
        // Read-only, a blank contact is noise and is dropped, exactly as
        // before. Editable, it's the only handle for filling it in, so it
        // stays with a placeholder — same call the Solution header makes for
        // its nullable attributes.
        ->filter(fn ($contact) => $canEdit || filled($contact['value']));

    $contactTypeOptions = collect(\App\Enums\ContactType::cases())
        ->map(fn ($type) => ['value' => $type->value, 'label' => $type->label()])
        ->all();
@endphp

<div id="{{ $domId }}" class="overflow-hidden rounded-bento border border-line bg-surface shadow-card">
    {{-- Identity strip on the gradient — first child of the overflow-hidden
         card, same pattern as solutions/detail-header.blade.php (see its
         comment for why this doesn't need its own radius). --}}
    {{-- `--ie-wash` overrides the hover wash of every click-to-edit datum on
         this strip: the default ink tint reads as a grey smudge over a pastel,
         where translucent white lifts it instead (see app.css). --}}
    <div class="relative flex flex-wrap items-start gap-4 p-6"
         style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%); --ie-wash: rgba(255, 255, 255, .5)">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
             style="background: var(--gradient-hero-seam)"></div>

        {{-- `upload-id` is not decoration: `avatar-upload.js` finds a tile's
             input by element id, and the side panel's person form renders its
             own `photo` upload — identical default ids would have the two
             fighting over the same picker while the panel is open. --}}
        {{-- `w-auto`: the tile is the same size and shape as the avatar it
             replaces, so the editor has no reason to be wider than it and push
             the identity column sideways while it's open. --}}
        <x-ui.inline-edit name="photo" type="file" :image-value="$photoUrl" upload-id="person-photo-inline"
                          image-shape="rounded-full"
                          :action="$fieldAction" :editable="$canEdit"
                          label="Foto" edit-class="w-auto" class="relative">
            <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="lg" class="shadow-sm" />
        </x-ui.inline-edit>

        <div class="relative min-w-0 flex-1">
            {{-- `input-class` mirrors the h1 below it: the name is read as a
                 28px display heading, so it's retyped as one too. --}}
            <x-ui.inline-edit name="name" :value="$person->name" :action="$fieldAction" :editable="$canEdit"
                              label="Nome" edit-class="min-w-72 max-w-xl"
                              input-class="!font-display !text-[28px] !font-bold !leading-tight !tracking-tight !text-[color:var(--color-glow-ink)]">
                <h1 class="font-display text-[28px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">{{ $person->name }}</h1>
            </x-ui.inline-edit>

            <x-ui.inline-edit name="job_title" :value="$person->job_title" :action="$fieldAction" :editable="$canEdit"
                              label="Cargo" empty="Adicionar cargo" edit-class="min-w-64 max-w-sm" class="mt-0.5">
                @if ($person->job_title)<p class="text-sm text-[color:var(--color-glow-ink)]/70">{{ $person->job_title }}</p>@endif
            </x-ui.inline-edit>

            {{-- The company name is click-to-edit like every other datum here;
                 the company's own page is what the ↗ next to it is for (`link`),
                 which is why the name itself is no longer an `<a>`. --}}
            <x-ui.inline-edit name="company_id" type="select" :options="$companyOptions" :value="$person->company_id"
                              :action="$fieldAction" :editable="$canEdit"
                              :link="$person->company ? route('companies.show', $person->company) : null" link-label="empresa"
                              label="Empresa" empty="Definir empresa" edit-class="min-w-64 max-w-sm" class="mt-1">
                @if ($person->company)
                    <span class="text-sm text-[color:var(--color-glow-ink)]/80">{{ $person->company->name }}</span>
                @endif
            </x-ui.inline-edit>
        </div>

        @can('update', $person)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.edit', $person) }}"
               class="relative inline-flex items-center gap-2 rounded-field border border-white/50 bg-white/60 px-3 py-1.5 text-sm font-semibold text-[color:var(--color-glow-ink)] backdrop-blur hover:bg-white/90">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>

    @if ($contactFields->isNotEmpty() || $canEdit)
        <div class="flex flex-wrap items-start gap-x-8 gap-y-3 border-t border-line p-6 text-sm">
            @foreach ($contactFields as $contact)
                {{-- `group/row` is the name x-ui.row-remove listens on (hover to
                     reveal, and step aside while this contact's editor is
                     open) — it's a row of the strip, whatever the tag says. --}}
                <div class="group/row">
                    <div class="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted">
                        {{ $contact['label'] }}

                        @if ($contact['removeUrl'])
                            {{-- Only extra contacts can be deleted; the label
                                 row is where the ✕ can sit without competing
                                 with the value's own click-to-edit target. --}}
                            <x-ui.row-remove :id="$contact['removeId']" :action="$contact['removeUrl']"
                                             confirm="Remover este contato?" label="Remover contato"
                                             size="small" />
                        @endif
                    </div>

                    <x-ui.inline-edit :name="$contact['name']" :type="$contact['type']" :value="$contact['value']"
                                      :action="$contact['action']" :editable="$canEdit" :label="$contact['label']">
                        @if (filled($contact['value']))<div class="text-ink">{{ $contact['value'] }}</div>@endif
                    </x-ui.inline-edit>
                </div>
            @endforeach

            @if ($canEdit)
                <div class="self-center">
                    <x-ui.inline-edit method="POST" :action="route('people.contacts.store', $person)"
                                     :fields="[
                                         ['name' => 'type', 'type' => 'select', 'options' => $contactTypeOptions, 'value' => \App\Enums\ContactType::Email->value, 'nullable' => false, 'label' => 'Tipo', 'class' => 'w-32 shrink-0'],
                                         ['name' => 'value', 'type' => 'text', 'label' => 'Valor', 'placeholder' => 'e-mail, telefone…', 'class' => 'min-w-0 flex-1'],
                                     ]"
                                     label="Contato" edit-class="min-w-72 max-w-md">
                        <x-ui.add-chip>Adicionar contato</x-ui.add-chip>
                    </x-ui.inline-edit>
                </div>
            @endif
        </div>
    @endif
</div>
