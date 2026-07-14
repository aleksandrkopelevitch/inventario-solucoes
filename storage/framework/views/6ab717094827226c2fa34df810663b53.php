<div id="<?php echo e($domId); ?>" class="space-y-2">
    <?php $__empty_1 = true; $__currentLoopData = $options; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
        <form id="attribute-option-form-<?php echo e($option->id); ?>" class="space-y-1.5">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <div class="flex items-center gap-1.5">
                <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['name' => 'label','value' => $option->label,'class' => 'flex-1 !py-1.5 text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'label','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($option->label),'class' => 'flex-1 !py-1.5 text-sm']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
                <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['dataAkAjax' => 'attribute-option-form-'.e($option->id).'','dataAkAction' => ''.e(route('attribute-options.update', $option)).'','class' => '!px-2.5 !py-1.5 text-xs']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-ajax' => 'attribute-option-form-'.e($option->id).'','data-ak-action' => ''.e(route('attribute-options.update', $option)).'','class' => '!px-2.5 !py-1.5 text-xs']); ?>
                    Salvar
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
                <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','dataAkAjax' => 'attribute-option-delete-'.e($option->id).'','dataAkAction' => ''.e(route('attribute-options.destroy', $option)).'','class' => '!shrink-0 !bg-transparent !p-1.5 !text-faint !shadow-none hover:!bg-raised hover:!text-crit','title' => 'Excluir']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','data-ak-ajax' => 'attribute-option-delete-'.e($option->id).'','data-ak-action' => ''.e(route('attribute-options.destroy', $option)).'','class' => '!shrink-0 !bg-transparent !p-1.5 !text-faint !shadow-none hover:!bg-raised hover:!text-crit','title' => 'Excluir']); ?>
                    <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-trash'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-4']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $attributes = $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $component = $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
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
            </div>
            <?php if($group->supportsIcon()): ?>
                <div class="flex items-center gap-1.5">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-field border border-line bg-canvas text-muted [&>svg]:size-3.5">
                        <?php if (isset($component)) { $__componentOriginal09f1c6e3a6cc14f99120f74a52ff5613 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal09f1c6e3a6cc14f99120f74a52ff5613 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.heroicon','data' => ['name' => $option->icon]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.heroicon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($option->icon)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal09f1c6e3a6cc14f99120f74a52ff5613)): ?>
<?php $attributes = $__attributesOriginal09f1c6e3a6cc14f99120f74a52ff5613; ?>
<?php unset($__attributesOriginal09f1c6e3a6cc14f99120f74a52ff5613); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal09f1c6e3a6cc14f99120f74a52ff5613)): ?>
<?php $component = $__componentOriginal09f1c6e3a6cc14f99120f74a52ff5613; ?>
<?php unset($__componentOriginal09f1c6e3a6cc14f99120f74a52ff5613); ?>
<?php endif; ?>
                    </span>
                    <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['name' => 'icon','value' => $option->icon,'placeholder' => 'ícone (heroicons outline)','title' => 'Nome do ícone heroicons (outline), ex.: cloud','class' => 'flex-1 !py-1 text-xs']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'icon','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($option->icon),'placeholder' => 'ícone (heroicons outline)','title' => 'Nome do ícone heroicons (outline), ex.: cloud','class' => 'flex-1 !py-1 text-xs']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
        <form id="attribute-option-delete-<?php echo e($option->id); ?>" class="hidden">
            <?php echo csrf_field(); ?>
            <?php echo method_field('DELETE'); ?>
        </form>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
        <p class="text-sm text-muted">Nenhum valor cadastrado ainda.</p>
    <?php endif; ?>

    <form id="attribute-option-create-<?php echo e($group->value); ?>" class="space-y-1.5 border-t border-line pt-3">
        <?php echo csrf_field(); ?>
        <div class="flex items-center gap-1.5">
            <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['name' => 'label','placeholder' => 'Novo valor...','class' => 'flex-1 !py-1.5 text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'label','placeholder' => 'Novo valor...','class' => 'flex-1 !py-1.5 text-sm']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['dataAkAjax' => 'attribute-option-create-'.e($group->value).'','dataAkAction' => ''.e(route('attribute-options.store', $group)).'','class' => '!px-2.5 !py-1.5 text-xs']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-ak-ajax' => 'attribute-option-create-'.e($group->value).'','data-ak-action' => ''.e(route('attribute-options.store', $group)).'','class' => '!px-2.5 !py-1.5 text-xs']); ?>
                Adicionar
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
        </div>
        <?php if($group->supportsIcon()): ?>
            <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['name' => 'icon','placeholder' => 'ícone (heroicons outline)','title' => 'Nome do ícone heroicons (outline), ex.: cloud','class' => '!py-1 text-xs']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'icon','placeholder' => 'ícone (heroicons outline)','title' => 'Nome do ícone heroicons (outline), ex.: cloud','class' => '!py-1 text-xs']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
        <?php endif; ?>
    </form>

    <?php if($group->supportsIcon()): ?>
        <p class="pt-1 text-[11px] text-faint">
            Ícone opcional (nome do <a href="https://heroicons.com" target="_blank" rel="noopener" class="underline hover:text-muted">heroicons</a> outline, ex.: <code class="rounded bg-raised px-1 py-0.5">cloud</code>) — aparece junto ao rótulo no mapa de integrações.
        </p>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/attribute-options/group-list.blade.php ENDPATH**/ ?>