<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => $person->name] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <a href="<?php echo e(route('people.index')); ?>" class="text-sm text-accent hover:underline">&larr; Pessoas</a>
    </div>

    <?php if (isset($component)) { $__componentOriginale24842507af579538e16e880154469fc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginale24842507af579538e16e880154469fc = $attributes; } ?>
<?php $component = App\View\Components\People\DetailHeader::resolve(['person' => $person] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('people.detail-header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\People\DetailHeader::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginale24842507af579538e16e880154469fc)): ?>
<?php $attributes = $__attributesOriginale24842507af579538e16e880154469fc; ?>
<?php unset($__attributesOriginale24842507af579538e16e880154469fc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginale24842507af579538e16e880154469fc)): ?>
<?php $component = $__componentOriginale24842507af579538e16e880154469fc; ?>
<?php unset($__componentOriginale24842507af579538e16e880154469fc); ?>
<?php endif; ?>

    <div class="mt-5 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas (<?php echo e($person->solutions->count()); ?>)</h2>
        <?php if($person->solutions->isEmpty()): ?>
            <p class="mt-2 text-sm text-muted">Nenhum sistema vinculado.</p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-line">
                <?php $__currentLoopData = $person->solutions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $solution): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex items-center justify-between py-2.5 text-sm">
                        <a href="<?php echo e(route('solutions.show', $solution)); ?>" class="font-medium text-ink hover:text-accent"><?php echo e($solution->name); ?></a>
                        <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-accent-line"><?php echo e(\App\Enums\PersonSolutionRole::from($solution->pivot->role)->label()); ?></span>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        <?php endif; ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/people/show.blade.php ENDPATH**/ ?>