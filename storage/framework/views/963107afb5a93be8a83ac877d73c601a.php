<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name',
    'checked' => false,
    'value' => '1',
    'id' => null,
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
    'name',
    'checked' => false,
    'value' => '1',
    'id' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $id ??= 'toggle-' . $name;
?>


<label for="<?php echo e($id); ?>" <?php echo e($attributes->class(['inline-flex cursor-pointer items-center gap-2'])); ?>>
    <input
        type="checkbox"
        id="<?php echo e($id); ?>"
        name="<?php echo e($name); ?>"
        value="<?php echo e($value); ?>"
        <?php if($checked): echo 'checked'; endif; ?>
        class="peer sr-only"
    />
    <span
        class="relative h-6 w-11 shrink-0 rounded-full bg-line-2 transition-colors
               peer-checked:bg-accent
               peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent
               after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform
               peer-checked:after:translate-x-5"
        aria-hidden="true"
    ></span>

    <?php if(trim($slot) !== ''): ?>
        <span class="text-sm text-body"><?php echo e($slot); ?></span>
    <?php endif; ?>
</label>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/toggle.blade.php ENDPATH**/ ?>