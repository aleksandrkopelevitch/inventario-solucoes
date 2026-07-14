<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['checked' => false, 'value' => '1']));

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

foreach (array_filter((['checked' => false, 'value' => '1']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="group grid size-4 grid-cols-1">
    <input
        type="checkbox"
        value="<?php echo e($value); ?>"
        <?php echo e($checked ? 'checked' : ''); ?>

        <?php echo e($attributes->class([
            'col-start-1 row-start-1 appearance-none rounded border border-line-2 bg-surface cursor-pointer',
            'checked:border-accent checked:bg-accent',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            'disabled:border-line-2 disabled:bg-raised disabled:cursor-not-allowed',
        ])); ?>

    />
    <svg viewBox="0 0 14 14" fill="none"
         class="pointer-events-none col-start-1 row-start-1 size-3.5 self-center justify-self-center stroke-white">
        <path d="M3 8L6 11L11 3.5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="opacity-0 group-has-checked:opacity-100" />
    </svg>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/checkbox.blade.php ENDPATH**/ ?>