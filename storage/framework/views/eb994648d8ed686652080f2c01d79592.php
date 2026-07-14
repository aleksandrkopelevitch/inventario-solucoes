<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Mapa de integrações'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Mapa de integrações</h1>
        <p class="mt-1 text-sm text-muted">Ecossistema completo: soluções como nós, integrações como arestas. O grafo é derivado da tabela de integrações.</p>
    </div>

    
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 sm:items-center">
        <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['dataAkGraphFilter' => 'status']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-graph-filter' => 'status']); ?>
            <option value="all">Todos os status</option>
            <option value="active">Ativas</option>
            <option value="in_development">Em desenvolvimento</option>
            <option value="planned">Planejadas</option>
            <option value="deprecated">Descontinuadas</option>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['dataAkGraphFilter' => 'category']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-graph-filter' => 'category']); ?>
            <option value="">Todas as categorias</option>
            <?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($option->value); ?>"><?php echo e($option->label); ?></option>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['dataAkGraphFilter' => 'directorate']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-graph-filter' => 'directorate']); ?>
            <option value="">Todas as diretorias</option>
            <?php $__currentLoopData = $directorates; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($option->value); ?>"><?php echo e($option->label); ?></option>
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

    <?php if (isset($component)) { $__componentOriginal05a95b4f1309c9c74597a1747c284a0b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal05a95b4f1309c9c74597a1747c284a0b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ecosystem-map','data' => ['id' => 'global-map','sourceUrl' => route('solutions.map.data'),'height' => '620px']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ecosystem-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'global-map','source-url' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('solutions.map.data')),'height' => '620px']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal05a95b4f1309c9c74597a1747c284a0b)): ?>
<?php $attributes = $__attributesOriginal05a95b4f1309c9c74597a1747c284a0b; ?>
<?php unset($__attributesOriginal05a95b4f1309c9c74597a1747c284a0b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal05a95b4f1309c9c74597a1747c284a0b)): ?>
<?php $component = $__componentOriginal05a95b4f1309c9c74597a1747c284a0b; ?>
<?php unset($__componentOriginal05a95b4f1309c9c74597a1747c284a0b); ?>
<?php endif; ?>

    
    <script>
        (function () {
            const shell = document.getElementById('global-map');
            const filters = document.querySelectorAll('[data-ak-graph-filter]');
            const baseUrl = <?php echo \Illuminate\Support\Js::from(route('solutions.map.data'))->toHtml() ?>;

            function reload() {
                const params = new URLSearchParams();
                filters.forEach((el) => {
                    const key = el.dataset.akGraphFilter;
                    if (el.type === 'checkbox') {
                        if (el.checked) params.set(key, '1');
                    } else if (el.value) {
                        params.set(key, el.value);
                    }
                });
                const qs = params.toString();
                shell.__ecosystemMapReload?.(qs ? `${baseUrl}?${qs}` : baseUrl);
            }

            filters.forEach((el) => el.addEventListener('change', reload));
        })();
    </script>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/solutions/map.blade.php ENDPATH**/ ?>