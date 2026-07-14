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
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/275c40d8800205e7dd6b9169ed8bd613.blade.php ENDPATH**/ ?>