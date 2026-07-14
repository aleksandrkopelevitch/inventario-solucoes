<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'type' => 'text',
    'value' => null,
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
    'type' => 'text',
    'value' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<input
    type="<?php echo e($type); ?>"
    <?php echo e($attributes->class([
        'block w-full rounded-field border border-line-2 bg-surface px-3 py-2 text-sm text-ink placeholder-faint',
        'transition duration-150 focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]',
        'disabled:bg-raised disabled:text-faint disabled:cursor-not-allowed',
    ])); ?>

    <?php if($value !== null): ?> value="<?php echo e($value); ?>" <?php endif; ?>
/>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/input.blade.php ENDPATH**/ ?>