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
<?php endif; ?><?php /**PATH /home/alexandre/inventario-solucoes/storage/framework/views/6b7fced003a67b57c851ab415372d3a5.blade.php ENDPATH**/ ?>