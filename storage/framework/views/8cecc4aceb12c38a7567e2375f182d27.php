<div id="<?php echo e($domId); ?>">
    <?php if($solutions->isEmpty()): ?>
        <div class="rounded-card border border-dashed border-line-2 bg-surface p-10 text-center text-sm text-muted">
            <p class="mb-1 font-medium text-ink">Nenhuma solução encontrada</p>
            <p class="mb-4">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkFiltersClearAll' => ''.e(json_encode(['formId' => 'solutions-filter-form', 'url' => route('solutions.index')])).'','class' => 'border border-line-2 !text-accent hover:!bg-accent-soft']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-filters-clear-all' => ''.e(json_encode(['formId' => 'solutions-filter-form', 'url' => route('solutions.index')])).'','class' => 'border border-line-2 !text-accent hover:!bg-accent-soft']); ?>
                Limpar filtros
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
    <?php else: ?>
        <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
            <?php $__currentLoopData = $solutions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $solution): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                    <div class="flex items-start gap-3">
                        <?php if (isset($component)) { $__componentOriginalc9b691e47e4aeaac2320d6494f20beb6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.logo','data' => ['name' => $solution->name,'src' => $solution->logo_path,'size' => 'md']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->logo_path),'size' => 'md']); ?>
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
                            <a href="<?php echo e(route('solutions.show', $solution)); ?>" class="block truncate font-display text-[17px] font-semibold text-ink hover:text-accent">
                                <?php if (isset($component)) { $__componentOriginald06f81674e339f2fef4efb5a394dde74 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald06f81674e339f2fef4efb5a394dde74 = $attributes; } ?>
<?php $component = App\View\Components\Ui\Highlight::resolve(['text' => $solution->name,'term' => $filters['search'] ?? null] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.highlight'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Ui\Highlight::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald06f81674e339f2fef4efb5a394dde74)): ?>
<?php $attributes = $__attributesOriginald06f81674e339f2fef4efb5a394dde74; ?>
<?php unset($__attributesOriginald06f81674e339f2fef4efb5a394dde74); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald06f81674e339f2fef4efb5a394dde74)): ?>
<?php $component = $__componentOriginald06f81674e339f2fef4efb5a394dde74; ?>
<?php unset($__componentOriginald06f81674e339f2fef4efb5a394dde74); ?>
<?php endif; ?>
                            </a>
                            <p class="truncate text-xs text-muted"><?php echo e($solution->category_label); ?><?php if($solution->directorate): ?> · <?php echo e($solution->directorate); ?><?php endif; ?></p>
                        </div>
                        <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $solution)): ?>
                            <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('solutions.edit', ['solution' => $solution, 'filter' => $filters])); ?>"
                               class="shrink-0 rounded-field p-1.5 text-faint transition-colors hover:bg-raised hover:text-accent" title="Editar">
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
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-[11px] font-medium">
                        <?php if($solution->environment): ?>
                            <span class="rounded-full bg-lime-soft px-2 py-0.5 text-lime-ink ring-1 ring-lime-line"><?php echo e($solution->environment_label); ?><?php if($solution->cloud): ?> · <?php echo e($solution->cloud_label); ?><?php endif; ?></span>
                        <?php endif; ?>
                        <?php if($solution->contract_status): ?>
                            <span class="rounded-full bg-hot-soft px-2 py-0.5 text-hot ring-1 ring-hot-line"><?php echo e($solution->contract_status_label); ?></span>
                        <?php endif; ?>
                        <span class="rounded-full bg-accent-soft px-2 py-0.5 text-accent ring-1 ring-accent-line"><?php echo e($solution->status_label); ?></span>
                    </div>

                    <div class="mt-auto flex items-center justify-between border-t border-line pt-2.5 text-xs text-muted">
                        <span class="flex items-center gap-3">
                            <span title="Fornecedor">
                                <?php if($solution->vendor): ?>
                                    <?php if (isset($component)) { $__componentOriginald06f81674e339f2fef4efb5a394dde74 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald06f81674e339f2fef4efb5a394dde74 = $attributes; } ?>
<?php $component = App\View\Components\Ui\Highlight::resolve(['text' => $solution->vendor->name,'term' => $filters['search'] ?? null] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.highlight'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Ui\Highlight::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald06f81674e339f2fef4efb5a394dde74)): ?>
<?php $attributes = $__attributesOriginald06f81674e339f2fef4efb5a394dde74; ?>
<?php unset($__attributesOriginald06f81674e339f2fef4efb5a394dde74); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald06f81674e339f2fef4efb5a394dde74)): ?>
<?php $component = $__componentOriginald06f81674e339f2fef4efb5a394dde74; ?>
<?php unset($__componentOriginald06f81674e339f2fef4efb5a394dde74); ?>
<?php endif; ?>
                                <?php else: ?>
                                    Sem fornecedor
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="flex items-center gap-2.5">
                            <span class="flex items-center gap-1" title="Integrações entrada / saída">
                                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-arrow-down-left'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-3.5 text-accent']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $attributes = $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $component = $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?><?php echo e($solution->active_in); ?>

                                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-arrow-up-right'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-3.5 text-accent']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $attributes = $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c)): ?>
<?php $component = $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c; ?>
<?php unset($__componentOriginal643fe1b47aec0b76658e1a0200b34b2c); ?>
<?php endif; ?><?php echo e($solution->active_out); ?>

                            </span>
                            <?php ($hasDocs = (bool) $solution->has_docs); ?>
                            <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                'bg-accent-soft text-accent' => $hasDocs,
                                'bg-raised text-muted' => ! $hasDocs,
                            ]); ?>" title="Documentação">
                                <?php echo e($hasDocs ? 'Documentado' : 'Sem doc'); ?>

                            </span>
                        </span>
                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/solutions/index.blade.php ENDPATH**/ ?>