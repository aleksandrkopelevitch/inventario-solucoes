<?php if (isset($component)) { $__componentOriginalea7b7095850fe8bc9657025b89ccf5a5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.textarea','data' => ['name' => 'bio','rows' => '3']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'bio','rows' => '3']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5)): ?>
<?php $attributes = $__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5; ?>
<?php unset($__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalea7b7095850fe8bc9657025b89ccf5a5)): ?>
<?php $component = $__componentOriginalea7b7095850fe8bc9657025b89ccf5a5; ?>
<?php unset($__componentOriginalea7b7095850fe8bc9657025b89ccf5a5); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/d84d83c854a0da45d9d417d49b8bcec7.blade.php ENDPATH**/ ?>