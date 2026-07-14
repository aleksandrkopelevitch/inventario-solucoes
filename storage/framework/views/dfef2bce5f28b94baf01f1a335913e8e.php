<?php
    $filterBind = ['formId' => 'solutions-filter-form', 'url' => route('solutions.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
?>

<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Soluções'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">
                Soluções
                <?php if (isset($component)) { $__componentOriginalf3c170868c48f540d56910d356aa8209 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf3c170868c48f540d56910d356aa8209 = $attributes; } ?>
<?php $component = App\View\Components\Solutions\ResultsCount::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.results-count'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\ResultsCount::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf3c170868c48f540d56910d356aa8209)): ?>
<?php $attributes = $__attributesOriginalf3c170868c48f540d56910d356aa8209; ?>
<?php unset($__attributesOriginalf3c170868c48f540d56910d356aa8209); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf3c170868c48f540d56910d356aa8209)): ?>
<?php $component = $__componentOriginalf3c170868c48f540d56910d356aa8209; ?>
<?php unset($__componentOriginalf3c170868c48f540d56910d356aa8209); ?>
<?php endif; ?>
            </h1>
            <p class="mt-1 text-sm text-muted">Catálogo das soluções de software da Leo Madeiras.</p>
        </div>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('create', \App\Models\Solution::class)): ?>
            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('solutions.create', ['filter' => $filters])); ?>"
               class="inline-flex items-center gap-2 rounded-field bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-press">
                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-plus'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-4']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $attributes = $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $component = $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?> Nova solução
            </a>
        <?php endif; ?>
    </div>

    
    <form id="solutions-filter-form" class="mb-3 flex flex-col gap-3">
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['type' => 'search','id' => 'solutions-search','name' => 'filter[search]','placeholder' => 'Buscar por nome, fornecedor ou responsável','class' => 'pr-9','value' => $filters['search'] ?? null,'dataAkSearchParam' => 'filter[search]','dataAkSearch' => ''.e(json_encode(['inputId' => 'solutions-search', 'url' => route('solutions.index')])).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','id' => 'solutions-search','name' => 'filter[search]','placeholder' => 'Buscar por nome, fornecedor ou responsável','class' => 'pr-9','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($filters['search'] ?? null),'data-ak-search-param' => 'filter[search]','data-ak-search' => ''.e(json_encode(['inputId' => 'solutions-search', 'url' => route('solutions.index')])).'']); ?>
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
            <p data-ak-search-hint="solutions-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[category]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['category'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[category]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['category'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Categoria</option>
                <?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($option->value); ?>" <?php if(($filters['category'] ?? '') === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[directorate]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['directorate'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[directorate]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['directorate'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Diretoria</option>
                <?php $__currentLoopData = $directorates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($option->value); ?>" <?php if(($filters['directorate'] ?? '') === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[environment]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['environment'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[environment]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['environment'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Hospedagem</option>
                <?php $__currentLoopData = $environments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($option->value); ?>" <?php if(($filters['environment'] ?? '') === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[contract]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['contract'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[contract]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['contract'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Contrato</option>
                <?php $__currentLoopData = $contractStatuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($option->value); ?>" <?php if(($filters['contract'] ?? '') === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
                <option value="">Status</option>
                <?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($option->value); ?>" <?php if(($filters['status'] ?? '') === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[sort]','dataAkFilters' => ''.e(json_encode($filterBind)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[sort]','data-ak-filters' => ''.e(json_encode($filterBind)).'']); ?>
                <option value="name" <?php if(($filters['sort'] ?? 'name') === 'name'): echo 'selected'; endif; ?>>Ordenar: Nome (A–Z)</option>
                <option value="status" <?php if(($filters['sort'] ?? 'name') === 'status'): echo 'selected'; endif; ?>>Ordenar: Status</option>
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

            <?php if (isset($component)) { $__componentOriginal1f715251ca27813040dd69c48bb81eec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal1f715251ca27813040dd69c48bb81eec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.label','data' => ['dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => 'col-span-2 !flex cursor-pointer items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-2 !text-[13.5px] !text-ink transition-colors has-checked:border-accent has-checked:bg-accent-soft has-checked:text-accent has-checked:font-semibold sm:col-span-3 lg:col-span-6']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => 'col-span-2 !flex cursor-pointer items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-2 !text-[13.5px] !text-ink transition-colors has-checked:border-accent has-checked:bg-accent-soft has-checked:text-accent has-checked:font-semibold sm:col-span-3 lg:col-span-6']); ?>
                <?php if (isset($component)) { $__componentOriginala6191e173a29d7cb3002715ea2e926a8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala6191e173a29d7cb3002715ea2e926a8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.checkbox','data' => ['name' => 'filter[undocumented]','checked' => (bool) ($filters['undocumented'] ?? false)]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[undocumented]','checked' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute((bool) ($filters['undocumented'] ?? false))]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala6191e173a29d7cb3002715ea2e926a8)): ?>
<?php $attributes = $__attributesOriginala6191e173a29d7cb3002715ea2e926a8; ?>
<?php unset($__attributesOriginala6191e173a29d7cb3002715ea2e926a8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala6191e173a29d7cb3002715ea2e926a8)): ?>
<?php $component = $__componentOriginala6191e173a29d7cb3002715ea2e926a8; ?>
<?php unset($__componentOriginala6191e173a29d7cb3002715ea2e926a8); ?>
<?php endif; ?>
                Somente sem documentação
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal1f715251ca27813040dd69c48bb81eec)): ?>
<?php $attributes = $__attributesOriginal1f715251ca27813040dd69c48bb81eec; ?>
<?php unset($__attributesOriginal1f715251ca27813040dd69c48bb81eec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal1f715251ca27813040dd69c48bb81eec)): ?>
<?php $component = $__componentOriginal1f715251ca27813040dd69c48bb81eec; ?>
<?php unset($__componentOriginal1f715251ca27813040dd69c48bb81eec); ?>
<?php endif; ?>
        </div>
    </form>

    <div class="mb-5">
        <?php if (isset($component)) { $__componentOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2 = $attributes; } ?>
<?php $component = App\View\Components\Solutions\FilterChips::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.filter-chips'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\FilterChips::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2)): ?>
<?php $attributes = $__attributesOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2; ?>
<?php unset($__attributesOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2)): ?>
<?php $component = $__componentOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2; ?>
<?php unset($__componentOriginalc5b8c5ed5cb4ab518211fb936ea2e1a2); ?>
<?php endif; ?>
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <?php if (isset($component)) { $__componentOriginal5102a01d8e39a8fe0fa8d4831e612ba9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5102a01d8e39a8fe0fa8d4831e612ba9 = $attributes; } ?>
<?php $component = App\View\Components\Solutions\Index::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.index'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\Index::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5102a01d8e39a8fe0fa8d4831e612ba9)): ?>
<?php $attributes = $__attributesOriginal5102a01d8e39a8fe0fa8d4831e612ba9; ?>
<?php unset($__attributesOriginal5102a01d8e39a8fe0fa8d4831e612ba9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5102a01d8e39a8fe0fa8d4831e612ba9)): ?>
<?php $component = $__componentOriginal5102a01d8e39a8fe0fa8d4831e612ba9; ?>
<?php unset($__componentOriginal5102a01d8e39a8fe0fa8d4831e612ba9); ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/solutions/index.blade.php ENDPATH**/ ?>