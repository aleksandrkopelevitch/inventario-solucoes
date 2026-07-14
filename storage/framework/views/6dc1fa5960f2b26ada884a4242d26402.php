<?php if (isset($component)) { $__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.file','data' => ['name' => 'anexo']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.file'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'anexo']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c)): ?>
<?php $attributes = $__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c; ?>
<?php unset($__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c)): ?>
<?php $component = $__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c; ?>
<?php unset($__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/8261986c071945a7c5c48b43a669a56d.blade.php ENDPATH**/ ?>