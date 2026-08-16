{{--
    ONE field of an `x-ui.inline-edit` editor. Internal to that component —
    it exists only so the multi-field loop there doesn't carry a five-branch
    type switch inline. Don't use it directly.

    Every field carries its own name in the hook itself
    (`data-ak-inline-edit-field="phone"`), which is how `inline-edit.js`
    builds the payload without a separate list of names to keep in step.
--}}
@props([
    'name',
    'type' => 'text',
    'value' => null,
    'options' => [],
    'nullable' => true,
    'label' => null,
    'placeholder' => null,
    'empty' => 'Não informado',
    'rows' => 3,
    // Typography of the value being edited — see the note on x-ui.inline-edit's
    // own `inputClass` prop. Applied last so it beats `$chrome` below.
    'inputClass' => null,
    // `file` only: current image URL for the preview tile, and a page-unique
    // id — `avatar-upload.js` wires the tile to its input by `getElementById`,
    // so two uploads sharing a name (this editor and the side panel's form,
    // both `photo`) would fight over the same ids.
    'imageValue' => null,
    'uploadId' => null,
    // `file` only: the tile takes the shape of the image it replaces, so a
    // round avatar doesn't turn into a square card mid-edit.
    'imageShape' => 'rounded-card',
])

@php
    // Chrome shared by every editor field, and the whole point of it is
    // continuity with what it replaced. Since 2026-08-14 the editor draws NO
    // BOX AT ALL: no border, no white fill, no shadow, no focus ring. What was
    // a hairline-framed field is now the read value's own hover wash, which
    // simply stays, plus a 1.5px rule under the text. The reasoning, which is
    // the whole reason this component exists, is that hover already promised
    // one thing (the wash) and clicking used to deliver another (a white box
    // with a shadow) — a jump of state in the middle of a page you're reading.
    // Keeping the wash removes the jump; the rule does the one useful thing the
    // border did, which is say where the field begins and ends.
    //
    // Three things here are load-bearing, and each is easy to undo:
    //
    // - `var(--ie-wash)` is read HERE, on the control, never aliased into a
    //   `:root` variable of its own. A custom property's `var()` is substituted
    //   on the element that DECLARES it, so `--ie-edit-bg: var(--ie-wash)` in
    //   `:root` would resolve to the ink tint for the whole app and lose the
    //   translucent white the three detail headers set on their pastel strip.
    //
    // - The rule is an INSET box-shadow, not a `border-bottom`: a border adds
    //   1.5px of height and shoves the text up as the editor opens, which is
    //   exactly what this component exists to avoid. It's repeated in the
    //   `focus:` variant only to beat x-forms.input's own focus ring — the
    //   editor opens focused, so that ring is the state the user actually sees.
    //
    // - Padding mirrors read mode's wash (`-mx-1.5 px-1.5 -my-0.5 py-0.5`), so
    //   the value keeps the position it was read in. The bottom corners stay
    //   square: rounding them under a straight rule draws a box again.
    //
    // `placeholder:!italic` is not decoration either. Without a border, a blank
    // field's editor would be a caret alone on the page — the italic faint
    // placeholder (the field's own label) is what's left to anchor the eye, and
    // it's the same italic faint treatment read mode gives "Não informado".
    //
    // Every utility is `!`-marked because it has to beat the SAME utility
    // inside x-forms.input/select/textarea: both strings land in one class
    // attribute, and which one wins there is decided by CSS order, not by who
    // wrote it last. That includes the focus pair — an unmarked
    // `focus:shadow-…` would lose to the important base shadow.
    $chrome = implode(' ', [
        '!rounded-t-field !rounded-b-none !border-0 !bg-[var(--ie-wash)] !py-0.5',
        '!shadow-[inset_0_-1.5px_0_0_var(--ie-rule)] focus:!shadow-[inset_0_-1.5px_0_0_var(--ie-rule)]',
        'placeholder:!italic',
    ]);
@endphp

@if ($type === 'select')
    {{-- `!pr-7` rather than a symmetric `!px-1.5`: the component's own chevron
         lives in that right-hand gutter — and with no border left to announce
         it, that chevron is now the ONLY thing saying this value opens a list,
         so the gutter it needs is not negotiable. --}}
    <x-forms.select data-ak-inline-edit-field="{{ $name }}" aria-label="{{ $label ?? $name }}"
                    class="{{ $chrome }} !pl-1.5 !pr-7 {{ $inputClass }}">
        @if ($nullable)
            <option value="">{{ $empty }}</option>
        @endif
        @foreach ($options as $option)
            <option value="{{ $option['value'] }}" @selected((string) $option['value'] === (string) $value)>{{ $option['label'] }}</option>
        @endforeach
    </x-forms.select>
@elseif ($type === 'textarea')
    {{-- Enter inserts a newline here, so `inline-edit.js` saves this one on
         Ctrl/Cmd+Enter instead. --}}
    <x-forms.textarea data-ak-inline-edit-field="{{ $name }}" :rows="$rows"
                      placeholder="{{ $placeholder ?? $label }}" aria-label="{{ $label ?? $name }}"
                      class="{{ $chrome }} !px-1.5 !leading-relaxed {{ $inputClass }}">{{ $value }}</x-forms.textarea>

    {{-- Every textarea in this component is a free-text field read back through
         x-ui.markdown, and nothing else on screen says so — the editor is a
         plain textarea, exactly as it looks. Without this line the formatting
         exists but nobody finds it. --}}
    <p class="mt-1 text-[11px] text-faint">
        Aceita Markdown — <code class="font-mono">**negrito**</code>,
        <code class="font-mono">- lista</code>,
        <code class="font-mono">[link](url)</code>
    </p>
@elseif ($type === 'file')
    {{-- The same mechanism as the user's own profile photo
         (`profile/edit.blade.php`): click the tile → picker → live preview,
         plus "Remover". The `_action` hidden input rides along as a second
         field, which is what makes removal reach the server. --}}
    {{-- Size and shape mirror the `lg` avatar/logo this replaces (`size-14`),
         so opening the editor doesn't move the image or change it into a
         different silhouette — the "Trocar" overlay is the only new thing. --}}
    {{-- No `accept` here: the component already sets `accept="image/*"`, and a
         second one would just be an invalid duplicate attribute the browser
         ignores. What actually gates the file is `imageRejectionReason()` in
         inline-edit.js plus the request's `image|mimes:` rule. --}}
    <x-forms.image-upload :name="$name" :value="$imageValue" :id="$uploadId"
                          size="size-14" :shape="$imageShape"
                          :input-attributes="['data-ak-inline-edit-field' => $name]"
                          :action-attributes="['data-ak-inline-edit-field' => $name . '_action']" />
@else
    <x-forms.input :type="$type" :value="$value" data-ak-inline-edit-field="{{ $name }}"
                   placeholder="{{ $placeholder ?? $label }}" aria-label="{{ $label ?? $name }}"
                   class="{{ $chrome }} !px-1.5 {{ $inputClass }}" />
@endif
