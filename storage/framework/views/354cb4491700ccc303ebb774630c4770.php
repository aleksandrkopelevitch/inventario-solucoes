<div id="<?php echo e($domId); ?>" class="rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
    <div class="flex flex-wrap items-start gap-4">
        <?php if (isset($component)) { $__componentOriginalc9b691e47e4aeaac2320d6494f20beb6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.logo','data' => ['name' => $company->name,'src' => $company->logo_path,'size' => 'lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($company->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($company->logo_path),'size' => 'lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6)): ?>
<?php $attributes = $__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6; ?>
<?php unset($__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc9b691e47e4aeaac2320d6494f20beb6)): ?>
<?php $component = $__componentOriginalc9b691e47e4aeaac2320d6494f20beb6; ?>
<?php unset($__componentOriginalc9b691e47e4aeaac2320d6494f20beb6); ?>
<?php endif; ?>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-[26px] font-semibold leading-tight text-ink"><?php echo e($company->name); ?></h1>
            <p class="mt-0.5 text-sm text-muted"><?php echo e($company->kind->label()); ?></p>
            <?php if($company->website): ?>
                <a href="<?php echo e($company->website); ?>" target="_blank" rel="noopener" class="mt-1 inline-block text-sm text-accent hover:underline"><?php echo e($company->website); ?></a>
            <?php endif; ?>
        </div>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $company)): ?>
            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('companies.edit', $company)); ?>"
               class="inline-flex items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-1.5 text-sm font-semibold text-ink hover:bg-raised">
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
<?php endif; ?> Editar
            </a>
        <?php endif; ?>
    </div>
    <?php if($company->notes): ?>
        <p class="mt-4 border-t border-line pt-4 text-sm text-body"><?php echo e($company->notes); ?></p>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/companies/detail-header.blade.php ENDPATH**/ ?>