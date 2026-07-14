<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => $title,'breadcrumbs' => $breadcrumbs ?? null] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="<?php echo \Illuminate\Support\Arr::toCssClasses([
        'mx-auto flex items-stretch rounded-card border border-line bg-white',
        //'max-w-3xl' => ! isset($pagesNav),
        //'max-w-5xl' => isset($pagesNav),
    ]); ?>">
        <?php if(isset($pagesNav)): ?>
            <?php if (isset($component)) { $__componentOriginal62f07f8702b03f672aa6c90f7984191e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal62f07f8702b03f672aa6c90f7984191e = $attributes; } ?>
<?php $component = App\View\Components\Documentation\PagesNav::resolve(['pages' => $pagesNav,'integrations' => $integrationsNav ?? [],'createPageUrl' => $createPageUrl] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('documentation.pages-nav'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Documentation\PagesNav::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal62f07f8702b03f672aa6c90f7984191e)): ?>
<?php $attributes = $__attributesOriginal62f07f8702b03f672aa6c90f7984191e; ?>
<?php unset($__attributesOriginal62f07f8702b03f672aa6c90f7984191e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal62f07f8702b03f672aa6c90f7984191e)): ?>
<?php $component = $__componentOriginal62f07f8702b03f672aa6c90f7984191e; ?>
<?php unset($__componentOriginal62f07f8702b03f672aa6c90f7984191e); ?>
<?php endif; ?>
        <?php endif; ?>

        <div class="min-w-0 flex-1">
            
            <div class="sticky top-14 z-10 flex items-center justify-between gap-3 rounded-t-card border-b border-line bg-white px-6 py-3">
                <span class="text-sm text-ink font-bold">
                    <?php echo e($backLabel); ?>

                </span>

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

            <div class="px-8">
                <?php if($canEdit): ?>
                    
                    <textarea data-ak-docs-source hidden><?php echo e($documentation); ?></textarea>

                    
                    <div class="mt-6 ak-docs-editor" data-ak-docs-editor
                        data-config="<?php echo e(json_encode(['uploadUrl' => $uploadUrl])); ?>"></div>

                    <p class="mt-6 text-xs text-muted">
                        Dica: digite <kbd class="rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px]">/</kbd>
                        no início de um bloco para inserir títulos, listas, tabelas, hints, abas, imagens e arquivos —
                        ou use Markdown direto (<code>## </code>, <code>- </code>, <code>> </code>, <code>```</code>).
                    </p>
                <?php else: ?>
                    <?php if(trim($renderedHtml) !== ''): ?>
                        
                        <textarea data-ak-docs-markdown hidden><?php echo e($documentation); ?></textarea>

                        <div class="html-content mt-6">
                            <?php echo $renderedHtml; ?>

                        </div>
                    <?php else: ?>
                        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                            Nenhuma documentação cadastrada ainda.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/documentation/edit.blade.php ENDPATH**/ ?>