@props([
    // PATCH/POST endpoint. Null/blank renders read-only, same as `editable: false`.
    'action' => null,
    // 'PATCH' to edit something that exists, 'POST' to create one (the
    // "+ Adicionar …" creators — same look, same gesture, different verb).
    'method' => 'PATCH',

    // ── One field (the common case) ──────────────────────────────────
    // Field name the endpoint expects (`phone`, `company_id`, `photo`, …).
    'name' => null,
    // text | email | tel | select | textarea | file
    'type' => 'text',
    // Raw value used to seed the field (NOT what read mode shows — that's the slot).
    'value' => null,
    // select only: [['value' => …, 'label' => …], …]
    'options' => [],
    // select only: renders the blank option, so the value can be cleared.
    'nullable' => true,
    'placeholder' => null,
    'rows' => 3,
    // file only — see the `imageValue`/`uploadId` notes on x-ui.inline-edit-field.
    'imageValue' => null,
    'uploadId' => null,
    // file only: shape of the upload tile. Pass `rounded-full` wherever the
    // image being edited is a round avatar, so the editor wears the same
    // silhouette as the thing it replaced.
    'imageShape' => 'rounded-card',

    // ── Several fields in one editor ─────────────────────────────────
    // [['name' => …, 'type' => …, …], …] — same keys as the single-field props
    // above, plus 'class' for that field's width. Used by the creators, where
    // one gesture needs two answers (contact type + value, system + role).
    'fields' => null,

    // Accessible name — "Telefone", "Empresa". Also the input's placeholder fallback.
    'label' => null,
    // Read-mode text when the slot renders nothing (blank value). It's the only
    // handle a blank field has, so it must stay clickable — same reasoning as
    // the Solution header's "Não informado" badges.
    'empty' => 'Não informado',
    'editable' => true,
    // The page this datum points at (the person's company, the system a link
    // row points at). Renders an ↗ next to the value, and that icon is the
    // ONLY navigation affordance — the value itself belongs to the editor,
    // like every other datum on the page. So the slot of a linked field must
    // render plain text, never its own `<a>`: two click targets over the same
    // words is exactly the ambiguity this split removes.
    'link' => null,
    // What the ↗ opens — "empresa", "sistema". Accessible name only; falls
    // back to the field's label.
    'linkLabel' => null,
    // Extra attributes for the ↗ itself, e.g. `['target' => '_blank', 'rel' =>
    // 'noopener']` when the link leaves the app (a company's website). Internal
    // links need none of it.
    'linkAttributes' => [],
    // Width of the editor, as a floor AND a ceiling. The floor: a shrink-to-fit
    // parent (a cell of the contacts strip) would collapse a `w-full` input to
    // nothing once read mode is hidden. The ceiling: a `flex-1` parent (the
    // identity column) stretches it across the whole card instead, which reads
    // as a form rather than as one field being retyped.
    'editClass' => 'min-w-48 max-w-xs',
    // Typography of the editor's input, matching what read mode shows: a name
    // rendered as a 28px display heading must not be retyped in 14px body text
    // — that swap is the single most jarring thing a click-to-edit page can do.
    // Utilities only, `!`-marked (they beat x-forms.input's own defaults — see
    // the note in x-ui.inline-edit-field). Anything read at the app's default
    // `text-sm` needs nothing here. Per-field override: an `inputClass` key
    // inside `fields`.
    'inputClass' => null,
])

@php
    // `ComponentSlot::isEmpty()` is a strict `=== ''`, and Blade hands us the
    // call site's newline + indentation, so it reports "not empty" for a slot
    // whose whole body is an `@if` that rendered nothing.
    $slotIsBlank = trim($slot->toHtml()) === '';
    $isEditable = $editable && filled($action);

    // A creator ("+ Adicionar …") already wears an affordance of its own — the
    // dashed chip its caller renders — so it gets neither the hover wash nor
    // the pencil: both would read as "edit this chip" rather than "add one".
    $isCreator = strtoupper($method) === 'POST';

    // The single-field props are sugar for a one-entry `fields` list — one code
    // path below, and callers that only edit one thing never see the array form.
    $editFields = $fields ?? [[
        'name'        => $name,
        'type'        => $type,
        'value'       => $value,
        'options'     => $options,
        'nullable'    => $nullable,
        'label'       => $label,
        'placeholder' => $placeholder,
        'rows'        => $rows,
        'imageValue'  => $imageValue,
        'uploadId'    => $uploadId,
        'inputClass'  => $inputClass,
    ]];

    $types = collect($editFields)->map(fn ($field) => $field['type'] ?? 'text');
    $hasFile = $types->contains('file');

    // An editor over nothing but an image: read mode there is the avatar/logo
    // itself, so its wash takes the image's own shape (a rounded-rect halo
    // around a circle would be the one square thing on the strip).
    $isImage = $hasFile && count($editFields) === 1;

    // Where the confirm/cancel pair goes. Beside a one-line field the block
    // keeps its height, so opening an editor doesn't shove everything below it
    // down the page — the whole reason this page is edited in place. A tall
    // (textarea, image) or multi-field editor is already changing the block's
    // height, and there the pair reads better on its own line.
    $stacked = $hasFile || $types->contains('textarea') || count($editFields) > 1;
@endphp

@if (! $isEditable)
    @if (filled($link))
        {{-- Read-only there's no editor to trigger, but the linked page still
             has to be reachable — and the value is plain text now, so the ↗ is
             the only thing that can reach it. --}}
        <span class="inline-flex items-center gap-1.5">
            {{ $slot }}
            <x-ui.external-link :href="$link" :label="$linkLabel ?? $label" :extra-attributes="$linkAttributes" />
        </span>
    @else
        {{-- Read-only viewer: byte-for-byte the markup the caller passed, no
             wrapper and no placeholder for blank values (they just don't render,
             exactly as before this component existed). --}}
        {{ $slot }}
    @endif
@else
    <div {{ $attributes->class(['group/ie']) }}
         data-ak-inline-edit="{{ json_encode(['action' => $action, 'method' => $method]) }}">

        {{-- Editing is a DELIBERATE gesture: a double click on the value, or a
             single click on the pencil, which is a real button. A single click
             over the words does what it does anywhere else on the page —
             selects text, follows the ↗ — so nothing is stolen from reading a
             record just because you're allowed to change it.

             A creator is the exception, and not an inconsistency: its read mode
             is a "+ Adicionar …" chip, an action rather than a value, so it
             answers to one click (and to Enter/Space, hence `role="button"`)
             like every other button in the app. --}}
        <div data-ak-inline-edit-read
             @if ($isCreator)
                 data-ak-inline-edit-open
                 role="button" tabindex="0"
                 aria-label="Adicionar {{ $label ?? $name }}"
                 title="Clique para adicionar"
             @else
                 title="Clique duas vezes para editar"
             @endif
             @class(['group/ie-read outline-none', 'cursor-pointer' => $isCreator])>
            {{-- The wash rides on this span, not on the block above it, for two
                 reasons. It has to HUG the value — stretched across the column
                 it reads as a text input that was always there, which is the
                 opposite of the point. And the block can't own its own width
                 (`w-fit` collapses a shrink-to-fit parent, e.g. a cell of the
                 contacts strip, to min-content and wraps "Não informado" in
                 half) nor its own `display` (inline-edit.js hides it by
                 toggling Tailwind's `hidden`, and between two `display`
                 utilities the winner is decided by stylesheet order — the read
                 mode stayed on screen UNDER the open editor).

                 The hit target stays the full block, which is the forgiving
                 half of the deal: aim anywhere on the line, see exactly which
                 words you're about to edit.

                 No `max-w-full` on the span either, tempting as it looks: an
                 inline-flex box already shrink-to-fits to the space it's given,
                 and a percentage max-width inside a shrink-to-fit parent
                 resolves against a width that isn't known yet — it collapses to
                 min-content and breaks "Não informado" across two lines. --}}
            <span @class([
                      'inline-flex items-center gap-1.5 transition-colors',
                      // Keyboard users get what the cursor gets — the wash plus a
                      // ring, since a wash alone is too quiet to answer a Tab.
                      'group-focus-visible/ie-read:bg-[var(--ie-wash)] group-focus-visible/ie-read:ring-2 group-focus-visible/ie-read:ring-accent/35',
                      // An image's wash takes the image's own shape, and brings
                      // its own radius with it — a second `border-radius` here
                      // would be a coin toss between two utilities, and `!`
                      // can't settle it (the class would only ever exist as a
                      // runtime concatenation, which the JIT never sees).
                      'rounded-field' => ! $isImage,
                      // The negative margins keep the wash from nudging the read
                      // text out of alignment with everything around it: it
                      // bleeds into the padding instead of adding to it.
                      '-mx-1.5 -my-0.5 px-1.5 py-0.5 group-hover/ie-read:bg-[var(--ie-wash)]' => ! $isCreator && ! $isImage,
                      '-m-1 p-1 group-hover/ie-read:bg-[var(--ie-wash)] ' . $imageShape => ! $isCreator && $isImage,
                  ])>
                @if ($slotIsBlank)
                    <span class="text-sm italic text-faint transition-colors group-hover/ie:text-muted">{{ $empty }}</span>
                @else
                    {{ $slot }}
                @endif

                @if (filled($link))
                    {{-- Always visible, unlike the pencil below: it's a
                         separate action, not a hint about this one. --}}
                    <x-ui.external-link :href="$link" :label="$linkLabel ?? $label" :extra-attributes="$linkAttributes" />
                @endif

                @unless ($isCreator)
                    {{-- The single-click way in, and the only one a keyboard or
                         a finger has — so it's a real button, not a decorative
                         hint: focusable, in the tab order, and permanently
                         visible where there is no hover to reveal it
                         (`pointer-coarse`). It holds its space at zero opacity
                         the rest of the time, so appearing never reflows the
                         line it sits on. --}}
                    {{-- `!size-5` matches the line box of the text beside it, so
                         a pencil on every datum doesn't quietly add height to
                         every row of every list. It grows to a real 32px target
                         exactly where that matters and where hover doesn't
                         exist: `pointer-coarse` — at 60% there, though, not
                         100%: a phone shows every pencil on the card at once,
                         and at full strength six of them shout over the data
                         they're attached to. --}}
                    <x-forms.button type="button" variant="ghost" data-ak-inline-edit-open
                                    class="!size-5 shrink-0 !rounded-full !p-0 !text-faint opacity-0 transition-opacity
                                           group-hover/ie:opacity-100 focus-visible:opacity-100
                                           pointer-coarse:!size-8 pointer-coarse:opacity-60
                                           hover:!bg-[var(--ie-wash)] hover:!text-ink"
                                    aria-label="Editar {{ $label ?? $name }}"
                                    title="Editar (ou clique duas vezes no valor)">
                        <x-heroicon-o-pencil class="size-3.5" />
                    </x-forms.button>
                @endunless
            </span>
        </div>

        {{-- Fades in rather than snapping: the editor lands on top of content
             the user is still reading, and 140ms is enough to see WHERE it
             landed. Neutralised by the reduced-motion guard in app.css. --}}
        <div data-ak-inline-edit-form class="hidden {{ $editClass }} max-w-full animate-[ak-fade_140ms_ease-out]">
            <div @class([
                     'flex max-w-full flex-wrap gap-1.5',
                     'items-center' => ! $stacked,
                     'items-start' => $stacked,
                     // Pulls the input's own left padding back out, so the value
                     // stays roughly where it was being read instead of sliding
                     // sideways as the editor opens. An image tile has no such
                     // padding to compensate, and shifting it would do the very
                     // thing this prevents.
                     '-ml-2' => ! $hasFile,
                 ])>
                @foreach ($editFields as $field)
                    <div class="{{ $field['class'] ?? 'min-w-0 flex-1' }}">
                        <x-ui.inline-edit-field
                            :name="$field['name']"
                            :type="$field['type'] ?? 'text'"
                            :value="$field['value'] ?? null"
                            :options="$field['options'] ?? []"
                            :nullable="$field['nullable'] ?? true"
                            :label="$field['label'] ?? null"
                            :placeholder="$field['placeholder'] ?? null"
                            :empty="$field['empty'] ?? $empty"
                            :rows="$field['rows'] ?? 3"
                            :input-class="$field['inputClass'] ?? $inputClass"
                            :image-value="$field['imageValue'] ?? null"
                            :upload-id="$field['uploadId'] ?? null"
                            :image-shape="$field['imageShape'] ?? $imageShape" />
                    </div>
                @endforeach

                @unless ($stacked)
                    <x-ui.inline-edit-actions />
                @endunless
            </div>

            @if ($stacked)
                <x-ui.inline-edit-actions stacked />
            @endif
        </div>
    </div>
@endif
