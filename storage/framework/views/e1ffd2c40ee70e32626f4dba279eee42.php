<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name' => '',
    'src' => null,
    'size' => 'md',
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
    'name' => '',
    'src' => null,
    'size' => 'md',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $sizes = [
        'sm' => 'size-8 text-xs',
        'md' => 'size-10 text-sm',
        'lg' => 'size-14 text-lg',
    ];
    $sizeClass = $sizes[$size] ?? $sizes['md'];

    $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));

    $url = $src
        ? (\Illuminate\Support\Str::startsWith($src, ['http://', 'https://', '/'])
            ? $src
            : \Illuminate\Support\Facades\Storage::disk('public')->url($src))
        : null;
?>

<?php if($url): ?>
    <img src="<?php echo e($url); ?>" alt="<?php echo e($name); ?>"
        <?php echo e($attributes->class([$sizeClass, 'shrink-0 rounded-field border border-line bg-surface object-contain p-1'])); ?>>
<?php else: ?>
    <span <?php echo e($attributes->class([$sizeClass, 'inline-flex shrink-0 items-center justify-center rounded-field bg-accent font-display font-bold text-white'])); ?>>
        <?php echo e($initial ?: '?'); ?>

    </span>
<?php endif; ?>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/ui/logo.blade.php ENDPATH**/ ?>