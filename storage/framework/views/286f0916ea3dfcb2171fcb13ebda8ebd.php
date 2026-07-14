<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['id' => null, 'name', 'value']));

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

foreach (array_filter((['id' => null, 'name', 'value']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="flex items-center gap-x-3">
    <input
        type="radio"
        id="<?php echo e($id ?? $name.'-'.$value); ?>"
        name="<?php echo e($name); ?>"
        value="<?php echo e($value); ?>"
        <?php echo e($attributes->class([
            'size-4 cursor-pointer accent-accent',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            'disabled:cursor-not-allowed',
        ])); ?>

    />
    <label for="<?php echo e($id ?? $name.'-'.$value); ?>"
           class="block text-sm font-medium leading-6 text-body cursor-pointer"><?php echo e($slot); ?></label>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/radio.blade.php ENDPATH**/ ?>