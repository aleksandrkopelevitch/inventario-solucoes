<?php if (isset($component)) { $__componentOriginal6c7b09ecb327498caf5d3513503a0831 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6c7b09ecb327498caf5d3513503a0831 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.toggle','data' => ['name' => 'ativo']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.toggle'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'ativo']); ?>Ativo <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6c7b09ecb327498caf5d3513503a0831)): ?>
<?php $attributes = $__attributesOriginal6c7b09ecb327498caf5d3513503a0831; ?>
<?php unset($__attributesOriginal6c7b09ecb327498caf5d3513503a0831); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6c7b09ecb327498caf5d3513503a0831)): ?>
<?php $component = $__componentOriginal6c7b09ecb327498caf5d3513503a0831; ?>
<?php unset($__componentOriginal6c7b09ecb327498caf5d3513503a0831); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/3465f8bbd53ce50c33fa1b9cbdc06350.blade.php ENDPATH**/ ?>