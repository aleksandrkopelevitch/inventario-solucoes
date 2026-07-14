<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => $title] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mx-auto max-w-3xl">
        
        <div class="sticky top-0 z-10 -mx-4 mb-6 flex items-center justify-between gap-3 border-b border-line bg-canvas/85 px-4 py-3 backdrop-blur">
            <a href="<?php echo e($backUrl); ?>" class="inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-arrow-left'); ?>
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
<?php endif; ?> <?php echo e($backLabel); ?>

            </a>

            <div class="flex items-center gap-3">
                <?php if($canEdit || trim($renderedHtml) !== ''): ?>
                    <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkDocsCopy' => true,'class' => '!h-9 !w-9 !p-0','ariaLabel' => 'Copiar Markdown','title' => 'Copiar Markdown']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-docs-copy' => true,'class' => '!h-9 !w-9 !p-0','aria-label' => 'Copiar Markdown','title' => 'Copiar Markdown']); ?>
                        <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-clipboard-document'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-5']); ?>
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

                <?php if($canEdit): ?>
                    <span data-ak-docs-status class="text-xs text-muted" aria-live="polite"></span>
                    <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','dataAkDocsSave' => true,'dataAction' => ''.e($saveUrl).'','class' => '!h-9 !px-4 !text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','data-ak-docs-save' => true,'data-action' => ''.e($saveUrl).'','class' => '!h-9 !px-4 !text-sm']); ?>
                        Salvar
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

                
                <?php if(isset($coverageSolution)): ?>
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $coverageSolution)): ?>
                        <div class="relative">
                            <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button','variant' => 'ghost','dataAkToggle' => 'docs-share-dropdown','dataAkToggleClasses' => 'hidden','dataAkToggleBlur' => 'true','class' => '!h-9 !w-9 !p-0','ariaLabel' => 'Compartilhar documentação']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button','variant' => 'ghost','data-ak-toggle' => 'docs-share-dropdown','data-ak-toggle-classes' => 'hidden','data-ak-toggle-blur' => 'true','class' => '!h-9 !w-9 !p-0','aria-label' => 'Compartilhar documentação']); ?>
                                <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-share'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-5']); ?>
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
                            <div id="docs-share-dropdown" class="hidden absolute right-0 top-full z-20 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
                                <?php if (isset($component)) { $__componentOriginal67ad4c65c0f0bd4156d73abc98dd999d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal67ad4c65c0f0bd4156d73abc98dd999d = $attributes; } ?>
<?php $component = App\View\Components\Solutions\SharePanel::resolve(['solution' => $coverageSolution] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('solutions.share-panel'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Solutions\SharePanel::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal67ad4c65c0f0bd4156d73abc98dd999d)): ?>
<?php $attributes = $__attributesOriginal67ad4c65c0f0bd4156d73abc98dd999d; ?>
<?php unset($__attributesOriginal67ad4c65c0f0bd4156d73abc98dd999d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal67ad4c65c0f0bd4156d73abc98dd999d)): ?>
<?php $component = $__componentOriginal67ad4c65c0f0bd4156d73abc98dd999d; ?>
<?php unset($__componentOriginal67ad4c65c0f0bd4156d73abc98dd999d); ?>
<?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent"><?php echo e($eyebrow); ?></p>
        <h1 class="mt-1 font-display text-3xl font-semibold text-ink"><?php echo e($title); ?></h1>

        
        <?php if($canEdit): ?>
            
            <textarea data-ak-docs-source hidden><?php echo e($documentation); ?></textarea>

            
            <div class="mt-6 rounded-card border border-line bg-white px-8 py-8 shadow-sm">
                <div data-ak-docs-editor
                    data-config="<?php echo e(json_encode(['uploadUrl' => $uploadUrl])); ?>"
                    class="ak-docs-editor"></div>
            </div>

            <p class="mt-6 text-xs text-muted">
                Dica: digite <kbd class="rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px]">/</kbd>
                no início de um bloco para inserir títulos, listas, tabelas, hints, abas, imagens e arquivos —
                ou use Markdown direto (<code>## </code>, <code>- </code>, <code>> </code>, <code>```</code>).
            </p>
        <?php else: ?>
            <?php if(trim($renderedHtml) !== ''): ?>
                
                <textarea data-ak-docs-markdown hidden><?php echo e($documentation); ?></textarea>

                <div class="html-content mt-6 rounded-card border border-line bg-white px-8 py-8 shadow-sm">
                    <?php echo $renderedHtml; ?>

                </div>
            <?php else: ?>
                <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                    Nenhuma documentação cadastrada ainda.
                </p>
            <?php endif; ?>
        <?php endif; ?>

        
        <?php if(isset($relatedDocs)): ?>
            <?php if($relatedDocs->isNotEmpty()): ?>
                <div class="mt-8">
                    <h2 class="font-display text-lg font-semibold text-ink">Documentações relacionadas</h2>
                    <p class="mt-1 text-sm text-muted">Documentação de cada integração desta solução.</p>

                    <div class="mt-3 divide-y divide-line rounded-card border border-line bg-surface">
                        <?php $__currentLoopData = $relatedDocs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <a href="<?php echo e($doc['url']); ?>" class="flex items-center justify-between gap-3 px-4 py-3 text-sm no-underline hover:bg-raised">
                                <span class="font-medium text-ink"><?php echo e($doc['label']); ?></span>
                                <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-accent-soft text-accent' => $doc['hasDocs'],
                                    'bg-raised text-muted' => ! $doc['hasDocs'],
                                ]); ?>">
                                    <?php echo e($doc['hasDocs'] ? 'Documentado' : 'Sem documentação'); ?>

                                </span>
                            </a>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                </div>
            <?php endif; ?>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/documentation/edit.blade.php ENDPATH**/ ?>