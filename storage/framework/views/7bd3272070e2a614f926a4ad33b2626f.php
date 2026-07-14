<?php
    // Classes do selo de status (documentado vs pendente), reaproveitadas em
    // solução e integração — mesmo par usado no índice de docs relacionadas.
    $badge = fn (bool $hasDocs) => [
        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        'bg-accent-soft text-accent' => $hasDocs,
        'bg-raised text-muted' => ! $hasDocs,
    ];
?>

<div id="<?php echo e($domId); ?>">
    <?php if($groups->isEmpty()): ?>
        <p class="rounded-card border border-dashed border-line bg-surface px-4 py-12 text-center text-sm text-muted">
            Nenhum item corresponde aos filtros.
        </p>
    <?php else: ?>
        <div class="space-y-3">
            <?php $__currentLoopData = $groups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php ($solution = $group['solution']); ?>
                <div class="rounded-card border border-line bg-surface shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                    
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <a href="<?php echo e($solution['showUrl']); ?>" class="truncate font-display text-[15px] font-semibold text-ink no-underline hover:text-accent">
                                <?php echo e($solution['name']); ?>

                            </a>
                            <?php if($solution['showStatus']): ?>
                                <span class="<?php echo \Illuminate\Support\Arr::toCssClasses($badge($solution['hasDocs'])); ?>">
                                    <?php echo e($solution['hasDocs'] ? 'Documentado' : 'Sem documentação'); ?>

                                </span>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo e($solution['url']); ?>"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                            <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-pencil-square'); ?>
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
                            Documentação
                        </a>
                    </div>

                    
                    <?php if($group['integrations']->isNotEmpty()): ?>
                        <ul class="divide-y divide-line border-t border-line">
                            <?php $__currentLoopData = $group['integrations']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $integration): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li>
                                    <a href="<?php echo e($integration['url']); ?>" class="flex items-center justify-between gap-3 px-4 py-2.5 pl-6 text-sm no-underline hover:bg-raised">
                                        <span class="flex min-w-0 items-center gap-2 text-ink">
                                            <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-arrows-right-left'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-3.5 shrink-0 text-faint']); ?>
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
                                            <span class="truncate"><?php echo e($integration['name']); ?></span>
                                        </span>
                                        <span class="<?php echo \Illuminate\Support\Arr::toCssClasses($badge($integration['hasDocs'])); ?>">
                                            <?php echo e($integration['hasDocs'] ? 'Documentado' : 'Sem documentação'); ?>

                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/documentation/hub.blade.php ENDPATH**/ ?>