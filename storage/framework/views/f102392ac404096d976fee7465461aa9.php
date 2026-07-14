<div id="<?php echo e($domId); ?>">
    <?php if($people->isEmpty()): ?>
        <div class="rounded-card border border-dashed border-line-2 bg-surface p-10 text-center text-sm text-muted">
            <p class="mb-1 font-medium text-ink">Nenhuma pessoa encontrada</p>
            <p class="mb-4">Ajuste a busca ou remova alguns filtros para ver mais resultados.</p>
            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkFiltersClearAll' => ''.e(json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')])).'','class' => 'border border-line-2 !text-accent hover:!bg-accent-soft']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-filters-clear-all' => ''.e(json_encode(['formId' => 'people-filter-form', 'url' => route('people.index')])).'','class' => 'border border-line-2 !text-accent hover:!bg-accent-soft']); ?>
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
            <?php $__currentLoopData = $people; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $person): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="flex items-start gap-3 rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
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
                        <a href="<?php echo e(route('people.show', $person)); ?>" class="block truncate font-display text-[16px] font-semibold text-ink hover:text-accent">
                            <?php if (isset($component)) { $__componentOriginald06f81674e339f2fef4efb5a394dde74 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald06f81674e339f2fef4efb5a394dde74 = $attributes; } ?>
<?php $component = App\View\Components\Ui\Highlight::resolve(['text' => $person->name,'term' => $filters['search'] ?? null] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
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
                        <?php if($person->job_title): ?><p class="truncate text-xs text-muted"><?php echo e($person->job_title); ?></p><?php endif; ?>
                        <?php if($person->company): ?>
                            <a href="<?php echo e(route('companies.show', $person->company)); ?>" class="mt-0.5 block truncate text-xs text-accent hover:underline">
                                <?php if (isset($component)) { $__componentOriginald06f81674e339f2fef4efb5a394dde74 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald06f81674e339f2fef4efb5a394dde74 = $attributes; } ?>
<?php $component = App\View\Components\Ui\Highlight::resolve(['text' => $person->company->name,'term' => $filters['search'] ?? null] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
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
                        <?php endif; ?>
                        <p class="mt-1.5 text-[11px] text-faint"><?php echo e($person->solutions_count); ?> <?php echo e(\Illuminate\Support\Str::plural('sistema', $person->solutions_count)); ?></p>
                    </div>
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $person)): ?>
                        <a href="#" data-ak-panel-open data-ak-panel-url="<?php echo e(route('people.edit', ['person' => $person, 'filter' => $filters])); ?>"
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
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/people/index.blade.php ENDPATH**/ ?>