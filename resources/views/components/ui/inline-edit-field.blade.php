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
    // continuity with what it replaced: a hairline instead of the panel forms'
    // heavier border, padding tight enough that the text keeps roughly the
    // position it had while read-only, and the app's usual accent ring once
    // focused (which is the state the user actually sees — the editor opens
    // focused).
    //
    // Every utility is `!`-marked because it has to beat the SAME utility
    // inside x-forms.input/select/textarea: both strings land in one class
    // attribute, and which one wins there is decided by CSS order, not by who
    // wrote it last. That includes the focus pair — an unmarked
    // `focus:border-accent` would lose to an important `!border-line`.
    $chrome = implode(' ', [
        '!rounded-field !border-line !bg-surface !py-1 !shadow-sm',
        'focus:!border-accent focus:!shadow-[0_0_0_3px_var(--color-accent-soft)]',
    ]);
@endphp

@if ($type === 'select')
    {{-- `!pr-7` rather than a symmetric `!px-2`: the component's own chevron
         lives in that right-hand gutter. --}}
    <x-forms.select data-ak-inline-edit-field="{{ $name }}" aria-label="{{ $label ?? $name }}"
                    class="{{ $chrome }} !pl-2 !pr-7 {{ $inputClass }}">
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
                      class="{{ $chrome }} !px-2 !leading-relaxed {{ $inputClass }}">{{ $value }}</x-forms.textarea>

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
                   class="{{ $chrome }} !px-2 {{ $inputClass }}" />
@endif
