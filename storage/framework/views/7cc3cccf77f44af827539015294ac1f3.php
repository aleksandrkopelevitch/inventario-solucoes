<?php
    $filterBind = ['formId' => 'people-filter-form', 'url' => route('people.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
?>

<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Pessoas'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
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
                Pessoas
                <?php if (isset($component)) { $__componentOriginal0425746c13d639fe382e9f0c79e32bb7 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0425746c13d639fe382e9f0c79e32bb7 = $attributes; } ?>
<?php $component = App\View\Components\People\ResultsCount::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('people.results-count'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\People\ResultsCount::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0425746c13d639fe382e9f0c79e32bb7)): ?>
<?php $attributes = $__attributesOriginal0425746c13d639fe382e9f0c79e32bb7; ?>
<?php unset($__attributesOriginal0425746c13d639fe382e9f0c79e32bb7); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0425746c13d639fe382e9f0c79e32bb7)): ?>
<?php $component = $__componentOriginal0425746c13d639fe382e9f0c79e32bb7; ?>
<?php unset($__componentOriginal0425746c13d639fe382e9f0c79e32bb7); ?>
<?php endif; ?>
            </h1>
            <p class="mt-1 text-sm text-muted">Responsáveis internos e contatos de fornecedores.</p>
        </div>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('create', \App\Models\Person::class)): ?>
            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('people.create', ['filter' => $filters])); ?>"
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
<?php endif; ?> Nova pessoa
            </a>
        <?php endif; ?>
    </div>

    <form id="people-filter-form" class="mb-3 flex flex-col gap-3">
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['type' => 'search','id' => 'people-search','name' => 'filter[search]','placeholder' => 'Buscar por nome, empresa ou sistema','class' => 'pr-9','value' => $filters['search'] ?? null,'dataAkSearchParam' => 'filter[search]','dataAkSearch' => ''.e(json_encode(['inputId' => 'people-search', 'url' => route('people.index')])).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','id' => 'people-search','name' => 'filter[search]','placeholder' => 'Buscar por nome, empresa ou sistema','class' => 'pr-9','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($filters['search'] ?? null),'data-ak-search-param' => 'filter[search]','data-ak-search' => ''.e(json_encode(['inputId' => 'people-search', 'url' => route('people.index')])).'']); ?>
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
            <p data-ak-search-hint="people-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[company]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['company'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[company]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['company'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Empresa</option>
                <?php $__currentLoopData = $companies; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $company): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($company->id); ?>" <?php if((string) ($filters['company'] ?? '') === (string) $company->id): echo 'selected'; endif; ?>><?php echo e($company->name); ?></option>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[role]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['role'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[role]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['role'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Papel</option>
                <?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $case): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($case->value); ?>" <?php if(($filters['role'] ?? '') === $case->value): echo 'selected'; endif; ?>><?php echo e($case->label()); ?></option>
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
        </div>
    </form>

    <div class="mb-5">
        <?php if (isset($component)) { $__componentOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc = $attributes; } ?>
<?php $component = App\View\Components\People\FilterChips::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('people.filter-chips'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\People\FilterChips::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc)): ?>
<?php $attributes = $__attributesOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc; ?>
<?php unset($__attributesOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc)): ?>
<?php $component = $__componentOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc; ?>
<?php unset($__componentOriginal0fb6c3963cecd39f0dc2fc0bbc5a84cc); ?>
<?php endif; ?>
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <?php if (isset($component)) { $__componentOriginal3d36f948f4d22debd8d70582b050ed29 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3d36f948f4d22debd8d70582b050ed29 = $attributes; } ?>
<?php $component = App\View\Components\People\Index::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('people.index'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\People\Index::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3d36f948f4d22debd8d70582b050ed29)): ?>
<?php $attributes = $__attributesOriginal3d36f948f4d22debd8d70582b050ed29; ?>
<?php unset($__attributesOriginal3d36f948f4d22debd8d70582b050ed29); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3d36f948f4d22debd8d70582b050ed29)): ?>
<?php $component = $__componentOriginal3d36f948f4d22debd8d70582b050ed29; ?>
<?php unset($__componentOriginal3d36f948f4d22debd8d70582b050ed29); ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/people/index.blade.php ENDPATH**/ ?>