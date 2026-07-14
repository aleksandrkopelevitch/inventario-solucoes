<?php if (isset($component)) { $__componentOriginal7edb3b69061800e185445c9bae1bdd4f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.radio','data' => ['name' => 'cor','value' => 'azul']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.radio'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'cor','value' => 'azul']); ?>Azul <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $attributes = $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $component = $__componentOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/0f87592185b101c20938722bdb6b5886.blade.php ENDPATH**/ ?>