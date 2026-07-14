<?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Nome do produto','name' => 'nome']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Nome do produto','name' => 'nome']); ?><?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['name' => 'nome']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'nome']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/9961da46e43be377b1ea5ad52d0db5cd.blade.php ENDPATH**/ ?>