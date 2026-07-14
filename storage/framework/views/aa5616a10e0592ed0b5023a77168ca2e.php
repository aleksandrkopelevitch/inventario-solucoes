<div id="<?php echo e($domId); ?>" class="mt-5 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Docs</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Documentação</h2>
        </div>

        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $solution)): ?>
            <a href="<?php echo e($editUrl); ?>"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40">
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
                <?php echo e(trim((string) $solution->documentation) !== '' ? 'Editar documentação' : 'Adicionar documentação'); ?>

            </a>
        <?php endif; ?>
    </div>

    <?php if(trim($html) !== ''): ?>
        <div class="html-content mt-4">
            <?php echo $html; ?>

        </div>
    <?php else: ?>
        <p class="mt-4 rounded-field border border-dashed border-line px-4 py-8 text-center text-sm text-muted">
            Nenhuma documentação cadastrada ainda.
        </p>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/solutions/documentation.blade.php ENDPATH**/ ?>