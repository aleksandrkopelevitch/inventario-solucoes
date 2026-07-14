<?php if (isset($component)) { $__componentOriginal2624037fcded6657b98353ea333c8c18 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2624037fcded6657b98353ea333c8c18 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.image-upload','data' => ['name' => 'logo']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.image-upload'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'logo']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2624037fcded6657b98353ea333c8c18)): ?>
<?php $attributes = $__attributesOriginal2624037fcded6657b98353ea333c8c18; ?>
<?php unset($__attributesOriginal2624037fcded6657b98353ea333c8c18); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2624037fcded6657b98353ea333c8c18)): ?>
<?php $component = $__componentOriginal2624037fcded6657b98353ea333c8c18; ?>
<?php unset($__componentOriginal2624037fcded6657b98353ea333c8c18); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/489167af0a30825b53be2f23d06cbede.blade.php ENDPATH**/ ?>