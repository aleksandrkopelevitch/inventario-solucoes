<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Visão geral'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Olá, <?php echo e($firstName); ?></h1>
        <p class="mt-1 text-sm text-muted">Portfólio de soluções da Leo Madeiras e estado da documentação.</p>
    </div>

    <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
        <?php $__currentLoopData = $metrics; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $metric): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="rounded-card border border-line bg-surface p-5 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                <div class="flex items-center gap-2 text-xs text-muted">
                    <?php if (isset($component)) { $__componentOriginal511d4862ff04963c3c16115c05a86a9d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal511d4862ff04963c3c16115c05a86a9d = $attributes; } ?>
<?php $component = Illuminate\View\DynamicComponent::resolve(['component' => 'heroicon-o-'.$metric['icon']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dynamic-component'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\DynamicComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-4 text-accent']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $attributes = $__attributesOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__attributesOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal511d4862ff04963c3c16115c05a86a9d)): ?>
<?php $component = $__componentOriginal511d4862ff04963c3c16115c05a86a9d; ?>
<?php unset($__componentOriginal511d4862ff04963c3c16115c05a86a9d); ?>
<?php endif; ?>
                    <?php echo e($metric['label']); ?>

                </div>
                <div class="mt-2 font-display text-[34px] font-semibold leading-none text-ink">
                    <?php echo e($metric['value']); ?>

                    <span class="ml-1 font-sans text-[13px] font-semibold text-muted"><?php echo e($metric['detail']); ?></span>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-5 rounded-card border border-[#eccccc] bg-crit-soft p-5">
        <div class="font-display text-[40px] font-semibold leading-none text-crit">0 / 81</div>
        <div class="min-w-[200px] flex-1">
            <b class="text-[15px] text-ink">Lacuna de documentação</b>
            <p class="mt-0.5 text-[13px] text-muted">Nenhuma solução possui arquitetura macro, componentes detalhados ou fluxos de negócio documentados. A cobertura será medida na Etapa 6 (F7).</p>
        </div>
    </div>

    <div class="mt-8 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Etapa 1</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Fundação de dados concluída</h2>
        </div>
        <p class="mt-2 text-sm text-muted">88 soluções (81 do inventário + 7 planejadas), 55 empresas, 106 pessoas e 10 integrações importadas. As telas de catálogo, integrações, pessoas e mapa entram nas próximas etapas.</p>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/profile/index.blade.php ENDPATH**/ ?>