<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => $solution->name] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <a href="<?php echo e(route('solutions.index')); ?>" class="text-sm text-accent hover:underline">&larr; Soluções</a>
    </div>

    
    <?php if (isset($component)) { $__componentOriginal302e3343eeaebddae3fef079ae391e31 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal302e3343eeaebddae3fef079ae391e31 = $attributes; } ?>
<?php $component = App\View\Components\Solutions\DetailHeader::resolve(['solution' => $solution] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.detail-header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\DetailHeader::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal302e3343eeaebddae3fef079ae391e31)): ?>
<?php $attributes = $__attributesOriginal302e3343eeaebddae3fef079ae391e31; ?>
<?php unset($__attributesOriginal302e3343eeaebddae3fef079ae391e31); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal302e3343eeaebddae3fef079ae391e31)): ?>
<?php $component = $__componentOriginal302e3343eeaebddae3fef079ae391e31; ?>
<?php unset($__componentOriginal302e3343eeaebddae3fef079ae391e31); ?>
<?php endif; ?>

    
    <div class="mt-5 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">F3</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Integrações</h2>
        </div>
        <p class="mt-1 text-sm text-muted">Selecione uma integração à esquerda para ver a visualização gráfica.</p>

        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-stretch">
            
            <div class="w-full shrink-0 lg:w-2/5 lg:max-w-sm">
                <?php if (isset($component)) { $__componentOriginal38a2695f6197d1e5d5840a8183d6f39a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal38a2695f6197d1e5d5840a8183d6f39a = $attributes; } ?>
<?php $component = App\View\Components\Solutions\IntegrationsMap::resolve(['solution' => $solution] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.integrations-map'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\IntegrationsMap::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal38a2695f6197d1e5d5840a8183d6f39a)): ?>
<?php $attributes = $__attributesOriginal38a2695f6197d1e5d5840a8183d6f39a; ?>
<?php unset($__attributesOriginal38a2695f6197d1e5d5840a8183d6f39a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal38a2695f6197d1e5d5840a8183d6f39a)): ?>
<?php $component = $__componentOriginal38a2695f6197d1e5d5840a8183d6f39a; ?>
<?php unset($__componentOriginal38a2695f6197d1e5d5840a8183d6f39a); ?>
<?php endif; ?>
            </div>

            
            <?php if (isset($component)) { $__componentOriginala3b85b86bc680ece828e48d6749faaa5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala3b85b86bc680ece828e48d6749faaa5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.solutions.integration-viz','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.integration-viz'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala3b85b86bc680ece828e48d6749faaa5)): ?>
<?php $attributes = $__attributesOriginala3b85b86bc680ece828e48d6749faaa5; ?>
<?php unset($__attributesOriginala3b85b86bc680ece828e48d6749faaa5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala3b85b86bc680ece828e48d6749faaa5)): ?>
<?php $component = $__componentOriginala3b85b86bc680ece828e48d6749faaa5; ?>
<?php unset($__componentOriginala3b85b86bc680ece828e48d6749faaa5); ?>
<?php endif; ?>
        </div>
    </div>

    
    <?php if (isset($component)) { $__componentOriginal65b1df551c3847aef646905905248c9d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal65b1df551c3847aef646905905248c9d = $attributes; } ?>
<?php $component = App\View\Components\Solutions\Documentation::resolve(['solution' => $solution] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.documentation'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\Documentation::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal65b1df551c3847aef646905905248c9d)): ?>
<?php $attributes = $__attributesOriginal65b1df551c3847aef646905905248c9d; ?>
<?php unset($__attributesOriginal65b1df551c3847aef646905905248c9d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal65b1df551c3847aef646905905248c9d)): ?>
<?php $component = $__componentOriginal65b1df551c3847aef646905905248c9d; ?>
<?php unset($__componentOriginal65b1df551c3847aef646905905248c9d); ?>
<?php endif; ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/solutions/show.blade.php ENDPATH**/ ?>