<?php
    $filterBind = ['formId' => 'documentation-filter-form', 'url' => route('documentation.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
?>

<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Documentação'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Documentação</h1>
        <p class="mt-1 text-sm text-muted">O que está documentado e o que ainda precisa — soluções e integrações do inventário.</p>
    </div>

    
    <div class="grid gap-3.5 sm:grid-cols-2">
        <?php $__currentLoopData = [
            'Soluções' => $counters['solutions'],
            'Integrações' => $counters['integrations'],
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $counter): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="rounded-card border border-line bg-surface p-5 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                <div class="flex items-baseline justify-between gap-2">
                    <div class="text-xs uppercase tracking-wide text-faint"><?php echo e($label); ?> documentadas</div>
                    <div class="text-sm text-muted"><?php echo e($counter['percent']); ?>%</div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="font-display text-[32px] font-semibold text-ink"><?php echo e($counter['documented']); ?></span>
                    <span class="text-sm text-muted">de <?php echo e($counter['total']); ?></span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-raised">
                    <div class="h-full rounded-full bg-accent" style="width: <?php echo e($counter['percent']); ?>%"></div>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <form id="documentation-filter-form" class="mb-3 mt-6 flex flex-col gap-3">
        <div class="max-w-md">
            <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['name' => 'search']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'search']); ?>
                <div class="relative">
                    <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['type' => 'search','id' => 'documentation-search','name' => 'filter[search]','placeholder' => 'Buscar por solução ou integração','class' => 'pr-9','value' => $filters['search'] ?? null,'dataAkSearchParam' => 'filter[search]','dataAkSearch' => ''.e(json_encode(['inputId' => 'documentation-search', 'url' => route('documentation.index')])).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','id' => 'documentation-search','name' => 'filter[search]','placeholder' => 'Buscar por solução ou integração','class' => 'pr-9','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($filters['search'] ?? null),'data-ak-search-param' => 'filter[search]','data-ak-search' => ''.e(json_encode(['inputId' => 'documentation-search', 'url' => route('documentation.index')])).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-arrow-path'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-filters-loading' => true,'class' => 'pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $attributes = $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $component = $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
                </div>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
            <p data-ak-search-hint="documentation-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:max-w-md">
            <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[type]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['type'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[type]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['type'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Tudo</option>
                <option value="solutions" <?php if(($filters['type'] ?? '') === 'solutions'): echo 'selected'; endif; ?>>Só soluções</option>
                <option value="integrations" <?php if(($filters['type'] ?? '') === 'integrations'): echo 'selected'; endif; ?>>Só integrações</option>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $attributes = $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $component = $__componentOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>

            <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[status]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['status'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[status]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['status'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Qualquer status</option>
                <option value="documented" <?php if(($filters['status'] ?? '') === 'documented'): echo 'selected'; endif; ?>>Documentado</option>
                <option value="pending" <?php if(($filters['status'] ?? '') === 'pending'): echo 'selected'; endif; ?>>Pendente</option>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $attributes = $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $component = $__componentOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
        </div>
    </form>

    <div data-ak-filters-dim class="transition-opacity">
        <?php if (isset($component)) { $__componentOriginal5c9235626cd75c9113379ff82f38d134 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5c9235626cd75c9113379ff82f38d134 = $attributes; } ?>
<?php $component = App\View\Components\Documentation\Hub::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('documentation.hub'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Documentation\Hub::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5c9235626cd75c9113379ff82f38d134)): ?>
<?php $attributes = $__attributesOriginal5c9235626cd75c9113379ff82f38d134; ?>
<?php unset($__attributesOriginal5c9235626cd75c9113379ff82f38d134); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5c9235626cd75c9113379ff82f38d134)): ?>
<?php $component = $__componentOriginal5c9235626cd75c9113379ff82f38d134; ?>
<?php unset($__componentOriginal5c9235626cd75c9113379ff82f38d134); ?>
<?php endif; ?>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf2b16bc3883246ba4659aff94e382522)): ?>
<?php $attributes = $__attributesOriginalf2b16bc3883246ba4659aff94e382522; ?>
<?php unset($__attributesOriginalf2b16bc3883246ba4659aff94e382522); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf2b16bc3883246ba4659aff94e382522)): ?>
<?php $component = $__componentOriginalf2b16bc3883246ba4659aff94e382522; ?>
<?php unset($__componentOriginalf2b16bc3883246ba4659aff94e382522); ?>
<?php endif; ?>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/documentation/index.blade.php ENDPATH**/ ?>