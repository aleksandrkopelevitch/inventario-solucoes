<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => $company->name] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <a href="<?php echo e(route('companies.index')); ?>" class="text-sm text-accent hover:underline">&larr; Empresas</a>
    </div>

    <?php if (isset($component)) { $__componentOriginalb49721b186343b63c3796324347e870e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb49721b186343b63c3796324347e870e = $attributes; } ?>
<?php $component = App\View\Components\Companies\DetailHeader::resolve(['company' => $company] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('companies.detail-header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Companies\DetailHeader::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb49721b186343b63c3796324347e870e)): ?>
<?php $attributes = $__attributesOriginalb49721b186343b63c3796324347e870e; ?>
<?php unset($__attributesOriginalb49721b186343b63c3796324347e870e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb49721b186343b63c3796324347e870e)): ?>
<?php $component = $__componentOriginalb49721b186343b63c3796324347e870e; ?>
<?php unset($__componentOriginalb49721b186343b63c3796324347e870e); ?>
<?php endif; ?>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <div class="rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
            <h2 class="font-display text-[18px] font-semibold text-ink">Pessoas (<?php echo e($company->people->count()); ?>)</h2>
            <?php if($company->people->isEmpty()): ?>
                <p class="mt-2 text-sm text-muted">Nenhuma pessoa cadastrada.</p>
            <?php else: ?>
                <ul class="mt-3 divide-y divide-line">
                    <?php $__currentLoopData = $company->people; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $person): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li class="flex items-center gap-3 py-2.5">
                            <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['name' => $person->name,'src' => $person->photo_path,'size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person->photo_path),'size' => 'sm']); ?>
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
                                <a href="<?php echo e(route('people.show', $person)); ?>" class="block truncate text-sm font-medium text-ink hover:text-accent"><?php echo e($person->name); ?></a>
                                <?php if($person->job_title): ?><span class="text-xs text-muted"><?php echo e($person->job_title); ?></span><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
            <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas fornecidos (<?php echo e($company->providedSolutions->count()); ?>)</h2>
            <?php if($company->providedSolutions->isEmpty()): ?>
                <p class="mt-2 text-sm text-muted">Nenhum sistema fornecido.</p>
            <?php else: ?>
                <ul class="mt-3 divide-y divide-line">
                    <?php $__currentLoopData = $company->providedSolutions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $solution): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li class="flex items-center justify-between py-2.5 text-sm">
                            <a href="<?php echo e(route('solutions.show', $solution)); ?>" class="font-medium text-ink hover:text-accent"><?php echo e($solution->name); ?></a>
                            <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent ring-1 ring-accent-line"><?php echo e($solution->category_label); ?></span>
                        </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf2b16bc3883246ba4659aff94e382522)): ?>
<?php $attributes = $__attributesOriginalf2b16bc3883246ba4659aff94e382522; ?>
<?php unset($__attributesOriginalf2b16bc3883246ba4659aff94e382522); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf2b16bc3883246ba4659aff94e382522)): ?>
<?php $component = $__componentOriginalf2b16bc3883246ba4659aff94e382522; ?>
<?php unset($__componentOriginalf2b16bc3883246ba4659aff94e382522); ?>
<?php endif; ?>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/companies/show.blade.php ENDPATH**/ ?>