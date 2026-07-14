<div id="<?php echo e($domId); ?>" data-ak-integration-list
    data-ak-solutions="<?php echo e(json_encode($solutionsList)); ?>"
    data-ak-protocols="<?php echo e(json_encode($protocolsList)); ?>"
    data-ak-statuses="<?php echo e(json_encode($statusesList)); ?>"
    class="flex flex-col gap-3" aria-label="Integrações da solução">
    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('create', App\Models\Integration::class)): ?>
        
        <form id="integration-create-form" class="flex items-center gap-2">
            <?php echo csrf_field(); ?>
            <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['type' => 'text','name' => 'name','placeholder' => 'Nome da nova integração (opcional)','class' => '!h-9 flex-1 !text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'text','name' => 'name','placeholder' => 'Nome da nova integração (opcional)','class' => '!h-9 flex-1 !text-sm']); ?>
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
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','dataAkAjax' => 'integration-create-form','dataAkAction' => ''.e(route('solutions.integrations.store', $solution)).'','class' => '!h-9 !shrink-0 !px-3 !text-xs']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','data-ak-ajax' => 'integration-create-form','data-ak-action' => ''.e(route('solutions.integrations.store', $solution)).'','class' => '!h-9 !shrink-0 !px-3 !text-xs']); ?>
                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-plus'); ?>
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
<?php endif; ?> Nova
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
        </form>
    <?php endif; ?>

    <div class="flex flex-col gap-2">
        <?php $__empty_1 = true; $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php ($integration = $row['integration']); ?>
            <div data-ak-integration-select="<?php echo e($integration->slug); ?>"
                data-integration-name="<?php echo e($integration->name); ?>"
                <?php if($row['graph']): ?> data-integration-graph="<?php echo e(json_encode($row['graph'])); ?>" <?php endif; ?>
                role="button" tabindex="0" aria-pressed="false"
                class="group flex cursor-pointer items-center justify-between gap-3 rounded-field border border-line bg-surface px-3.5 py-2.5 transition-colors hover:border-accent-line hover:bg-accent-soft/40 aria-pressed:border-lime aria-pressed:bg-surface aria-pressed:ring-2 aria-pressed:ring-lime aria-pressed:hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                <span class="min-w-0">
                    <span data-ak-integration-name class="block truncate text-sm font-medium text-ink"><?php echo e($integration->name); ?></span>
                    <?php if($row['summary']): ?>
                        <span data-ak-integration-summary class="block truncate font-mono text-xs text-muted"><?php echo e($row['summary']); ?></span>
                    <?php endif; ?>
                </span>
                <span class="flex shrink-0 items-center gap-1.5">
                    <span data-ak-integration-status class="inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-accent-line"><?php echo e($integration->status->label()); ?></span>
                    <a href="<?php echo e(route('solutions.integrations.docs.edit', [$solution, $integration])); ?>"
                        title="Documentação da integração"
                        class="inline-flex shrink-0 items-center rounded-field p-1.5 text-muted transition-colors hover:bg-accent-soft hover:text-accent"
                        onclick="event.stopPropagation()">
                        <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-document-text'); ?>
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
                    </a>
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('delete', $integration)): ?>
                        <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkAjax' => 'integration-delete-'.e($integration->id).'','dataAkAction' => ''.e(route('solutions.integrations.destroy', [$solution, $integration])).'','dataAkConfirm' => 'Excluir a integração &quot;'.e($integration->name).'&quot;? Esta ação não pode ser desfeita.','title' => 'Excluir integração','class' => '!shrink-0 !p-1.5 hover:!text-crit']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-ajax' => 'integration-delete-'.e($integration->id).'','data-ak-action' => ''.e(route('solutions.integrations.destroy', [$solution, $integration])).'','data-ak-confirm' => 'Excluir a integração &quot;'.e($integration->name).'&quot;? Esta ação não pode ser desfeita.','title' => 'Excluir integração','class' => '!shrink-0 !p-1.5 hover:!text-crit']); ?>
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
                    <?php endif; ?>
                </span>
            </div>
            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('delete', $integration)): ?>
                <form id="integration-delete-<?php echo e($integration->id); ?>" class="hidden">
                    <?php echo csrf_field(); ?>
                    <?php echo method_field('DELETE'); ?>
                </form>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="rounded-field border border-dashed border-line px-3 py-6 text-center text-sm text-muted">
                Nenhuma integração cadastrada.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/solutions/integrations-map.blade.php ENDPATH**/ ?>