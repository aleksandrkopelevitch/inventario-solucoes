<?php if (isset($component)) { $__componentOriginala2b2e0c43feb3b6dc0c1da68ce145c14 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala2b2e0c43feb3b6dc0c1da68ce145c14 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.public-docs','data' => ['title' => $title,'heading' => $solution->name,'nav' => $nav]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.public-docs'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($title),'heading' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->name),'nav' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($nav)]); ?>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent"><?php echo e($eyebrow); ?></p>
            <h1 class="mt-1 font-display text-3xl font-semibold text-ink"><?php echo e($title); ?></h1>
        </div>

        <?php if(trim($renderedHtml) !== ''): ?>
            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkDocsCopy' => true,'class' => '!h-9 shrink-0 !gap-1.5 !px-3 !text-sm','ariaLabel' => 'Copiar Markdown']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-docs-copy' => true,'class' => '!h-9 shrink-0 !gap-1.5 !px-3 !text-sm','aria-label' => 'Copiar Markdown']); ?>
                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-clipboard-document'); ?>
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
                <span>Copiar Markdown</span>
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

    <?php if(trim($renderedHtml) !== ''): ?>
        
        <textarea data-ak-docs-markdown hidden><?php echo e($markdown); ?></textarea>

        <div class="html-content mt-6 rounded-card border border-line bg-white px-6 py-8 shadow-sm sm:px-8">
            <?php echo $renderedHtml; ?>

        </div>
    <?php else: ?>
        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
            Nenhuma documentação cadastrada ainda.
        </p>
    <?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala2b2e0c43feb3b6dc0c1da68ce145c14)): ?>
<?php $attributes = $__attributesOriginala2b2e0c43feb3b6dc0c1da68ce145c14; ?>
<?php unset($__attributesOriginala2b2e0c43feb3b6dc0c1da68ce145c14); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala2b2e0c43feb3b6dc0c1da68ce145c14)): ?>
<?php $component = $__componentOriginala2b2e0c43feb3b6dc0c1da68ce145c14; ?>
<?php unset($__componentOriginala2b2e0c43feb3b6dc0c1da68ce145c14); ?>
<?php endif; ?>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/public/docs.blade.php ENDPATH**/ ?>