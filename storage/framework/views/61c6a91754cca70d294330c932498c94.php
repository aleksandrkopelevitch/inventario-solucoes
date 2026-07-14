<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name',
    'items' => [],            // preselected: [['value'=>, 'label'=>, 'role'=>null], ...] or plain strings
    'roles' => [],            // optional per-chip role options: [['value'=>, 'label'=>], ...]
    'placeholder' => 'Adicionar e pressionar Enter',
    'searchUrl' => null,      // optional: GET {searchUrl}?q=... -> {"results":[{"id":,"name":}]}. When set, chips can only be added by picking a result — no free-text Enter.
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
    'items' => [],            // preselected: [['value'=>, 'label'=>, 'role'=>null], ...] or plain strings
    'roles' => [],            // optional per-chip role options: [['value'=>, 'label'=>], ...]
    'placeholder' => 'Adicionar e pressionar Enter',
    'searchUrl' => null,      // optional: GET {searchUrl}?q=... -> {"results":[{"id":,"name":}]}. When set, chips can only be added by picking a result — no free-text Enter.
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $items = collect($items)->map(fn ($i) => is_array($i)
        ? array_merge(['value' => null, 'label' => null, 'role' => null], $i)
        : ['value' => $i, 'label' => $i, 'role' => null]);

    $config = ['name' => $name, 'roles' => array_values($roles), 'searchUrl' => $searchUrl];
?>


<div
    data-ak-chips="<?php echo e(json_encode($config)); ?>"
    data-ak-chips-next="<?php echo e($items->count()); ?>"
    <?php echo e($attributes->class(['flex w-full flex-col gap-2 rounded-field border border-line-2 bg-surface p-2 focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]'])); ?>

>
    <div data-ak-chips-list class="flex flex-wrap gap-1.5 empty:hidden">
        <?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <span data-ak-chip class="inline-flex items-center gap-1 rounded-full bg-accent-soft py-1 pl-2.5 pr-1 text-xs font-semibold text-ink ring-1 ring-accent-line">
                <span><?php echo e($item['label']); ?></span>
                <?php if(! empty($roles)): ?>
                    <select name="<?php echo e($name); ?>[<?php echo e($i); ?>][role]" class="rounded bg-transparent text-xs text-ink focus:outline-none">
                        <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($role['value']); ?>" <?php if(($item['role'] ?? null) === $role['value']): echo 'selected'; endif; ?>><?php echo e($role['label']); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                <?php endif; ?>
                <button type="button" data-ak-chip-remove class="ml-0.5 rounded-full px-1 leading-none text-muted hover:bg-accent-line hover:text-ink" aria-label="Remover">&times;</button>
                <input type="hidden" name="<?php echo e($name); ?>[<?php echo e($i); ?>][value]" value="<?php echo e($item['value']); ?>">
                <input type="hidden" name="<?php echo e($name); ?>[<?php echo e($i); ?>][label]" value="<?php echo e($item['label']); ?>">
            </span>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    <div class="relative">
        <input
            type="text"
            data-ak-chips-input
            placeholder="<?php echo e($placeholder); ?>"
            autocomplete="off"
            class="w-full bg-transparent px-1 py-1 text-sm text-ink placeholder-faint focus:outline-none"
        />
        <?php if($searchUrl): ?>
            <div data-ak-chips-results class="absolute inset-x-0 top-full z-10 mt-1 hidden max-h-48 overflow-y-auto rounded-field border border-line-2 bg-surface py-1 shadow-lg"></div>
        <?php endif; ?>
    </div>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/chips.blade.php ENDPATH**/ ?>