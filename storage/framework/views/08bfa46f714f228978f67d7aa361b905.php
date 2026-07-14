<div id="<?php echo e($domId); ?>" class="<?php echo \Illuminate\Support\Arr::toCssClasses(['flex flex-wrap items-center gap-2 transition-[margin] duration-200', 'hidden' => empty($chips)]); ?>">
    <?php $__currentLoopData = $chips; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $chip): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <span data-ak-chip class="animate-chip-pop inline-flex items-center gap-1.5 rounded-full border border-accent-line bg-accent-soft py-1 pl-3 pr-1.5 text-xs font-semibold text-accent transition duration-150 ease-in">
            <?php if($chip['label']): ?>
                <span class="font-medium text-accent/70"><?php echo e($chip['label']); ?>:</span>
            <?php endif; ?>
            <?php echo e($chip['value']); ?>

            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkFiltersClear' => ''.e(json_encode(['formId' => 'people-filter-form', 'field' => $chip['field'], 'url' => route('people.index')])).'','ariaLabel' => 'Remover filtro '.e($chip['value']).'','class' => '!rounded-full !p-0 !text-xs size-4 shrink-0 bg-accent/15 !text-accent hover:!bg-accent hover:!text-white']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-filters-clear' => ''.e(json_encode(['formId' => 'people-filter-form', 'field' => $chip['field'], 'url' => route('people.index')])).'','aria-label' => 'Remover filtro '.e($chip['value']).'','class' => '!rounded-full !p-0 !text-xs size-4 shrink-0 bg-accent/15 !text-accent hover:!bg-accent hover:!text-white']); ?>
                &times;
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $attributes = $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $component = $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
        </span>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    <?php if(count($chips)): ?>
        <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkFiltersClearAll' => ''.e(json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')])).'','class' => '!rounded-none !p-0 !text-xs underline decoration-line-2 underline-offset-2 hover:!bg-transparent']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-filters-clear-all' => ''.e(json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')])).'','class' => '!rounded-none !p-0 !text-xs underline decoration-line-2 underline-offset-2 hover:!bg-transparent']); ?>
            Limpar tudo
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $attributes = $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804)): ?>
<?php $component = $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804; ?>
<?php unset($__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804); ?>
<?php endif; ?>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/people/filter-chips.blade.php ENDPATH**/ ?>