<?php
    $contacts = collect([['E-mail', $person->email], ['Telefone', $person->phone]])
        ->concat($person->contacts->map(fn ($c) => [$c->type->label(), $c->value]))
        ->filter(fn ($c) => filled($c[1]));
?>

<div id="<?php echo e($domId); ?>" class="rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
    <div class="flex flex-wrap items-start gap-4">
        <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['name' => $person->name,'src' => $person->photo_path,'size' => 'lg']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person->photo_path),'size' => 'lg']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $attributes = $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $component = $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-[26px] font-semibold leading-tight text-ink"><?php echo e($person->name); ?></h1>
            <?php if($person->job_title): ?><p class="mt-0.5 text-sm text-muted"><?php echo e($person->job_title); ?></p><?php endif; ?>
            <?php if($person->company): ?>
                <a href="<?php echo e(route('companies.show', $person->company)); ?>" class="mt-1 inline-block text-sm text-accent hover:underline"><?php echo e($person->company->name); ?></a>
            <?php endif; ?>
        </div>
        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $person)): ?>
            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('people.edit', $person)); ?>"
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

    <?php if($contacts->isNotEmpty()): ?>
        <div class="mt-5 flex flex-wrap gap-x-8 gap-y-2 border-t border-line pt-5 text-sm">
            <?php $__currentLoopData = $contacts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$label, $value]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted"><?php echo e($label); ?></div>
                    <div class="text-ink"><?php echo e($value); ?></div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/people/detail-header.blade.php ENDPATH**/ ?>