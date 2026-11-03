@props([
    'formId',
    'action',                 // the PATCH endpoint that syncs the set
    'items',                  // [['value' => id, 'label' => name], …] — the current set
    'submit' => 'Salvar',     // PT-BR button label
    'placeholder' => 'Buscar solução e pressionar Enter',
])

{{-- Choosing a SET of catalog solutions and saving it in one go: the chips'
     search over `solutions.search`, then one PATCH. The chips' hidden inputs
     submit as `solutions[i][value]`; an empty set is valid and means "none".
     Used by a caderno's "Soluções documentadas" and a diagram's "Declarados à
     mão", which wrote the same form twice. --}}
<form id="{{ $formId }}" {{ $attributes }}>
    @csrf
    @method('PATCH')

    <x-forms.chips name="solutions" :items="$items" :placeholder="$placeholder"
        :search-url="route('solutions.search')" />

    <x-forms.button data-ak-ajax="{{ $formId }}" data-ak-action="{{ $action }}" class="mt-3 !h-9 !text-sm">
        {{ $submit }}
    </x-forms.button>
</form>
