<?php
    $filterBind = ['formId' => 'companies-filter-form', 'url' => route('companies.index'), 'event' => 'change'];
    $activeClass = '!border-accent !bg-accent-soft !text-accent !font-semibold';
?>

<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Empresas'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
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
                Empresas
                <?php if (isset($component)) { $__componentOriginal6a57f6ca2028d22800b9df23d75bd739 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a57f6ca2028d22800b9df23d75bd739 = $attributes; } ?>
<?php $component = App\View\Components\Companies\ResultsCount::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('companies.results-count'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Companies\ResultsCount::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a57f6ca2028d22800b9df23d75bd739)): ?>
<?php $attributes = $__attributesOriginal6a57f6ca2028d22800b9df23d75bd739; ?>
<?php unset($__attributesOriginal6a57f6ca2028d22800b9df23d75bd739); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a57f6ca2028d22800b9df23d75bd739)): ?>
<?php $component = $__componentOriginal6a57f6ca2028d22800b9df23d75bd739; ?>
<?php unset($__componentOriginal6a57f6ca2028d22800b9df23d75bd739); ?>
<?php endif; ?>
            </h1>
            <p class="mt-1 text-sm text-muted">Fornecedores, parceiros e áreas internas.</p>
        </div>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('create', \App\Models\Company::class)): ?>
            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('companies.create', ['filter' => $filters])); ?>"
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
<?php endif; ?> Nova empresa
            </a>
        <?php endif; ?>
    </div>

    <form id="companies-filter-form" class="mb-3 flex flex-col gap-3">
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['type' => 'search','id' => 'companies-search','name' => 'filter[search]','placeholder' => 'Buscar por nome','class' => 'pr-9','value' => $filters['search'] ?? null,'dataAkSearchParam' => 'filter[search]','dataAkSearch' => ''.e(json_encode(['inputId' => 'companies-search', 'url' => route('companies.index')])).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'search','id' => 'companies-search','name' => 'filter[search]','placeholder' => 'Buscar por nome','class' => 'pr-9','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($filters['search'] ?? null),'data-ak-search-param' => 'filter[search]','data-ak-search' => ''.e(json_encode(['inputId' => 'companies-search', 'url' => route('companies.index')])).'']); ?>
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
            <p data-ak-search-hint="companies-search" class="mt-1.5 h-4 text-xs text-hot"></p>
        </div>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => 'filter[kind]','dataAkFilters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['kind'] ?? null) ? $activeClass : '').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'filter[kind]','data-ak-filters' => ''.e(json_encode($filterBind)).'','class' => ''.e(filled($filters['kind'] ?? null) ? $activeClass : '').'']); ?>
                <option value="">Tipo</option>
                <?php $__currentLoopData = $kinds; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $case): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($case->value); ?>" <?php if(($filters['kind'] ?? '') === $case->value): echo 'selected'; endif; ?>><?php echo e($case->label()); ?></option>
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
        <?php if (isset($component)) { $__componentOriginal9f68e2d2026e9a813c3fe28c40d8df60 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9f68e2d2026e9a813c3fe28c40d8df60 = $attributes; } ?>
<?php $component = App\View\Components\Companies\FilterChips::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('companies.filter-chips'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Companies\FilterChips::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9f68e2d2026e9a813c3fe28c40d8df60)): ?>
<?php $attributes = $__attributesOriginal9f68e2d2026e9a813c3fe28c40d8df60; ?>
<?php unset($__attributesOriginal9f68e2d2026e9a813c3fe28c40d8df60); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9f68e2d2026e9a813c3fe28c40d8df60)): ?>
<?php $component = $__componentOriginal9f68e2d2026e9a813c3fe28c40d8df60; ?>
<?php unset($__componentOriginal9f68e2d2026e9a813c3fe28c40d8df60); ?>
<?php endif; ?>
    </div>

    <div data-ak-filters-dim class="transition-opacity">
        <?php if (isset($component)) { $__componentOriginal676c54a61ed0828b922f7c324b0804d4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal676c54a61ed0828b922f7c324b0804d4 = $attributes; } ?>
<?php $component = App\View\Components\Companies\Index::resolve(['filters' => $filters] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('companies.index'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Companies\Index::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal676c54a61ed0828b922f7c324b0804d4)): ?>
<?php $attributes = $__attributesOriginal676c54a61ed0828b922f7c324b0804d4; ?>
<?php unset($__attributesOriginal676c54a61ed0828b922f7c324b0804d4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal676c54a61ed0828b922f7c324b0804d4)): ?>
<?php $component = $__componentOriginal676c54a61ed0828b922f7c324b0804d4; ?>
<?php unset($__componentOriginal676c54a61ed0828b922f7c324b0804d4); ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/companies/index.blade.php ENDPATH**/ ?>