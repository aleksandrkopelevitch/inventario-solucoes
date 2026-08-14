@php
    // Punctual editing lives on this header (`x-ui.inline-edit`): every datum
    // is click-to-edit for whoever can update the company, and byte-for-byte
    // the old read-only markup for everyone else. The "Editar" panel stays for
    // what a single gesture can't express (and for editing several at once).
    // Same shape as people/detail-header.blade.php.
    $fieldAction = $canEdit ? route('companies.field.update', $company) : null;

    $kindOptions = collect($kinds)->map(fn ($kind) => ['value' => $kind->value, 'label' => $kind->label()])->all();

    $logoUrl = $company->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path)
        : null;
@endphp

<div id="{{ $domId }}" class="overflow-hidden rounded-bento border border-line bg-surface shadow-card">
    {{-- Identity strip on the gradient — same pattern as
         solutions/detail-header.blade.php. --}}
    {{-- `--ie-wash` overrides the hover wash of every click-to-edit datum on
         this strip — same reasoning as the person header (see app.css). --}}
    <div class="relative flex flex-wrap items-start gap-4 p-6"
         style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%); --ie-wash: rgba(255, 255, 255, .5)">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
             style="background: var(--gradient-hero-seam)"></div>

        {{-- `upload-id` is not decoration: `avatar-upload.js` finds a tile's
             input by element id, and the side panel's company form renders its
             own `logo` upload — identical default ids would have the two
             fighting over the same picker while the panel is open. --}}
        {{-- `w-auto`: the tile matches the logo it replaces, so the editor has
             no reason to be wider and shove the identity column sideways. --}}
        <x-ui.inline-edit name="logo" type="file" :image-value="$logoUrl" upload-id="company-logo-inline"
                          :action="$fieldAction" :editable="$canEdit"
                          label="Logo" edit-class="w-auto" class="relative">
            <x-ui.logo :name="$company->name" :src="$company->logo_path" size="lg" class="shadow-sm" />
        </x-ui.inline-edit>

        <div class="relative min-w-0 flex-1">
            {{-- `input-class` mirrors the h1 below it: read as a 28px display
                 heading, retyped as one. --}}
            <x-ui.inline-edit name="name" :value="$company->name" :action="$fieldAction" :editable="$canEdit"
                              label="Nome" edit-class="min-w-72 max-w-xl"
                              input-class="!font-display !text-[28px] !font-bold !leading-tight !tracking-tight !text-[color:var(--color-glow-ink)]">
                <h1 class="font-display text-[28px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">{{ $company->name }}</h1>
            </x-ui.inline-edit>

            <x-ui.inline-edit name="kind" type="select" :options="$kindOptions" :value="$company->kind->value"
                              :nullable="false" :action="$fieldAction" :editable="$canEdit"
                              label="Tipo" edit-class="min-w-48 max-w-xs" class="mt-0.5">
                <p class="text-sm text-[color:var(--color-glow-ink)]/70">{{ $company->kind->label() }}</p>
            </x-ui.inline-edit>

            {{-- The website is the one link here, so it follows the app-wide
                 rule: the URL text opens the editor and the ↗ opens the site
                 (in a new tab — it's the only outbound link in the app, hence
                 the `target`/`rel` riding along on the icon). --}}
            <x-ui.inline-edit name="website" type="url" :value="$company->website"
                              :action="$fieldAction" :editable="$canEdit"
                              :link="$company->website" link-label="site"
                              :link-attributes="['target' => '_blank', 'rel' => 'noopener']"
                              label="Site" empty="Adicionar site" edit-class="min-w-72 max-w-sm" class="mt-1">
                @if ($company->website)
                    <span class="text-sm text-[color:var(--color-glow-ink)]/80">{{ $company->website }}</span>
                @endif
            </x-ui.inline-edit>
        </div>

        @can('update', $company)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('companies.edit', $company) }}"
               class="relative inline-flex items-center gap-2 rounded-field border border-white/50 bg-white/60 px-3 py-1.5 text-sm font-semibold text-[color:var(--color-glow-ink)] backdrop-blur hover:bg-white/90">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>

    {{-- Editable, the notes strip is always there (it's the only handle for
         writing the first note); read-only it renders exactly as before —
         nothing at all when there's nothing to read. --}}
    @if ($company->notes || $canEdit)
        <div class="border-t border-line p-6">
            <x-ui.inline-edit name="notes" type="textarea" :value="$company->notes" :rows="4"
                              :action="$fieldAction" :editable="$canEdit"
                              label="Anotações" empty="Adicionar anotações" edit-class="w-full max-w-full">
                {{-- Markdown on the reading side, plain textarea on the
                     editing side — see x-ui.markdown. --}}
                <x-ui.markdown :text="$company->notes" class="text-sm leading-relaxed text-body" />
            </x-ui.inline-edit>
        </div>
    @endif
</div>
