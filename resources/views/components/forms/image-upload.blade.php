@props([
    'name' => 'image',
    'value' => null,
    'placeholder' => 'https://placehold.co/240x240/eef2f7/94a3b8?text=Imagem',
    'id' => null,
    'size' => 'h-32 w-32',
    // Radius of the tile. Defaults to the card radius every form in the app
    // uses; `x-ui.inline-edit` passes `rounded-full` when the image it's
    // editing in place is a round avatar, so clicking it doesn't turn a circle
    // into a square mid-edit.
    'shape' => 'rounded-card',
    // Extra attributes for the two inputs this component owns. `$attributes`
    // lands on the wrapper, so a caller that needs to hook the actual `<input
    // type="file">` (or the `{name}_action` one that "Remover" writes to) has
    // no way in otherwise — which is what `x-ui.inline-edit` needs to submit
    // this tile outside a `<form>`.
    'inputAttributes' => [],
    'actionAttributes' => [],
])

@php
    $uid           = $id ?? \Illuminate\Support\Str::slug($name) . '-img';
    $inputId       = $uid . '-file';
    $bgId          = $uid . '-preview';
    $removeBtnId   = $uid . '-remove-btn';
    $removeInputId = $uid . '-remove';
    $current       = $value ?: $placeholder;

    $addConfig = [
        'inputId'        => $inputId,
        'action'         => 'addAvatar',
        'targetImgBgId'  => $bgId,
        'removeButtonId' => $removeBtnId,
    ];

    $removeConfig = [
        'inputId'             => $inputId,
        'action'              => 'removeAvatar',
        'targetImgBgId'       => $bgId,
        'defaultAvatarImgUrl' => $placeholder,
        'removeAvatarInputId' => $removeInputId,
        'confirm'             => 'Remover esta imagem?',
    ];
@endphp

{{-- Reuses resources/js/modules/avatar-upload.js (data-ak-avatar-upload).
     Click preview -> open file picker -> live preview. "Remover" flags the
     hidden *_action input so the controller can delete the stored media. --}}
<div {{ $attributes->class(['inline-flex flex-col items-start gap-2']) }}>
    <input type="file" id="{{ $inputId }}" name="{{ $name }}" accept="image/*" class="hidden"
           {{ new \Illuminate\View\ComponentAttributeBag($inputAttributes) }} />
    <input type="hidden" id="{{ $removeInputId }}" name="{{ $name }}_action" value=""
           {{ new \Illuminate\View\ComponentAttributeBag($actionAttributes) }} />

    <div
        data-ak-avatar-upload="{{ json_encode($addConfig) }}"
        class="group relative cursor-pointer overflow-hidden ring-1 ring-line-2 {{ $shape }} {{ $size }}"
    >
        <div id="{{ $bgId }}" class="h-full w-full bg-cover bg-center"
             style="background-image:url('{{ $current }}')"></div>
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity group-hover:opacity-100">
            <span class="text-xs font-medium text-white">Trocar</span>
        </div>
    </div>

    <button
        type="button"
        id="{{ $removeBtnId }}"
        data-ak-avatar-upload="{{ json_encode($removeConfig) }}"
        class="{{ $value ? '' : 'hidden' }} text-xs text-crit hover:underline"
    >Remover</button>
</div>
