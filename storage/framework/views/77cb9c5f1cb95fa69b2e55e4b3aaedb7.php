<?php if (isset($component)) { $__componentOriginal1f715251ca27813040dd69c48bb81eec = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal1f715251ca27813040dd69c48bb81eec = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.label','data' => ['for' => 'nome']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['for' => 'nome']); ?>Nome <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal1f715251ca27813040dd69c48bb81eec)): ?>
<?php $attributes = $__attributesOriginal1f715251ca27813040dd69c48bb81eec; ?>
<?php unset($__attributesOriginal1f715251ca27813040dd69c48bb81eec); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal1f715251ca27813040dd69c48bb81eec)): ?>
<?php $component = $__componentOriginal1f715251ca27813040dd69c48bb81eec; ?>
<?php unset($__componentOriginal1f715251ca27813040dd69c48bb81eec); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/134ab5049910db3ad21cd4479e9f5dcc.blade.php ENDPATH**/ ?>