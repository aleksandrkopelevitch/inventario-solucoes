<?php if (isset($component)) { $__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.chips','data' => ['name' => 'tags']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.chips'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'tags']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8)): ?>
<?php $attributes = $__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8; ?>
<?php unset($__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8)): ?>
<?php $component = $__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8; ?>
<?php unset($__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/d2cd191fd85b67a26f1ddedce259f79c.blade.php ENDPATH**/ ?>