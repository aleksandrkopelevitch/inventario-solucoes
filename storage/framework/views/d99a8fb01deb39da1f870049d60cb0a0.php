<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name' => 'image',
    'value' => null,
    'placeholder' => 'https://placehold.co/240x240/eef2f7/94a3b8?text=Imagem',
    'id' => null,
    'size' => 'h-32 w-32',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'name' => 'image',
    'value' => null,
    'placeholder' => 'https://placehold.co/240x240/eef2f7/94a3b8?text=Imagem',
    'id' => null,
    'size' => 'h-32 w-32',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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
?>


<div <?php echo e($attributes->class(['inline-flex flex-col items-start gap-2'])); ?>>
    <input type="file" id="<?php echo e($inputId); ?>" name="<?php echo e($name); ?>" accept="image/*" class="hidden" />
    <input type="hidden" id="<?php echo e($removeInputId); ?>" name="<?php echo e($name); ?>_action" value="" />

    <div
        data-ak-avatar-upload="<?php echo e(json_encode($addConfig)); ?>"
        class="group relative cursor-pointer overflow-hidden rounded-card ring-1 ring-line-2 <?php echo e($size); ?>"
    >
        <div id="<?php echo e($bgId); ?>" class="h-full w-full bg-cover bg-center"
             style="background-image:url('<?php echo e($current); ?>')"></div>
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity group-hover:opacity-100">
            <span class="text-xs font-medium text-white">Trocar</span>
        </div>
    </div>

    <button
        type="button"
        id="<?php echo e($removeBtnId); ?>"
        data-ak-avatar-upload="<?php echo e(json_encode($removeConfig)); ?>"
        class="<?php echo e($value ? '' : 'hidden'); ?> text-xs text-crit hover:underline"
    >Remover</button>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/image-upload.blade.php ENDPATH**/ ?>