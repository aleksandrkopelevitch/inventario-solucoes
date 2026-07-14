<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title' => null,
    'heading' => '',
    'nav' => null,
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
    'title' => null,
    'heading' => '',
    'nav' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($title ? $title . ' · Documentação' : 'Documentação'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="min-h-screen bg-white text-body font-sans text-[14.5px] antialiased">

    
    <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-sm font-bold text-white">L</span>
        <div class="min-w-0">
            <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-accent">Documentação</p>
            <p class="truncate font-display text-base font-semibold leading-tight text-ink"><?php echo e($heading); ?></p>
        </div>
    </header>

    <div class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-8 px-4 py-6 md:grid-cols-[260px_1fr] md:px-6 md:py-10">

        
        <?php if($nav): ?>
            <aside class="md:sticky md:top-[4.5rem] md:h-max">
                <p class="px-2 pb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted">Nesta solução</p>
                <nav class="flex flex-col gap-0.5">
                    <?php $__currentLoopData = $nav; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <a href="<?php echo e($item['url']); ?>"
                           class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                               'flex items-center justify-between gap-2 rounded-field px-3 py-2 text-sm no-underline transition-colors',
                               'bg-accent-soft font-semibold text-accent' => $item['active'],
                               'text-body hover:bg-raised' => ! $item['active'],
                           ]); ?>">
                            <span class="truncate"><?php echo e($item['label']); ?></span>
                            <?php if (! ($item['hasDocs'])): ?>
                                <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-faint">vazio</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </nav>
            </aside>
        <?php endif; ?>

        
        <main class="min-w-0">
            <?php echo e($slot); ?>

        </main>
    </div>

    
    <div id="toast-container" class="fixed right-4 top-4 z-50 flex w-80 flex-col gap-2">
        <div id="toast-template" class="hidden rounded-card border border-line bg-surface p-4 opacity-0 shadow-lg transition-all duration-200">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 shrink-0">
                    <span data-icon-success class="hidden text-base text-lime-ink">✓</span>
                    <span data-icon-warning class="hidden text-base text-hot">⚠</span>
                    <span data-icon-error class="hidden text-base text-crit">✕</span>
                    <span data-icon-info class="hidden text-base text-accent">ℹ</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p data-slot="title" class="text-sm font-semibold text-ink"></p>
                    <p data-slot="content" class="mt-0.5 text-sm text-muted"></p>
                </div>
                <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','class' => '!rounded-none !p-0 !text-lg !leading-none !font-normal shrink-0 !text-faint hover:!bg-transparent hover:!text-body']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','class' => '!rounded-none !p-0 !text-lg !leading-none !font-normal shrink-0 !text-faint hover:!bg-transparent hover:!text-body']); ?>× <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $attributes = $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $component = $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
            </div>
            <div class="mt-3 h-0.5 overflow-hidden rounded-full bg-raised">
                <div data-timer class="h-full rounded-full bg-accent" style="width:100%"></div>
            </div>
        </div>
    </div>

</body>
</html>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/layouts/public-docs.blade.php ENDPATH**/ ?>