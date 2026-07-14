<div id="<?php echo e($domId); ?>">
    <div class="overflow-hidden rounded-card border border-line bg-surface shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        
        <div class="relative flex flex-wrap items-start gap-4 p-6">
            
            <div class="pointer-events-none absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-accent-soft/60 to-transparent"></div>

            <?php if (isset($component)) { $__componentOriginalc9b691e47e4aeaac2320d6494f20beb6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.logo','data' => ['name' => $solution->name,'src' => $solution->logo_path,'size' => 'lg','class' => 'relative shadow-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->logo_path),'size' => 'lg','class' => 'relative shadow-sm']); ?>
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

            <div class="relative min-w-0 flex-1">
                <h1 class="font-display text-[28px] font-semibold leading-tight text-ink"><?php echo e($solution->name); ?></h1>

                <?php if($solution->vendor): ?>
                    <a href="<?php echo e(route('companies.show', $solution->vendor)); ?>"
                       class="mt-2 inline-flex items-center gap-2 rounded-full border border-line bg-surface/70 py-0.5 pl-0.5 pr-3 text-sm text-muted backdrop-blur transition hover:border-line-2 hover:text-accent">
                        <?php if (isset($component)) { $__componentOriginalc9b691e47e4aeaac2320d6494f20beb6 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc9b691e47e4aeaac2320d6494f20beb6 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.logo','data' => ['name' => $solution->vendor->name,'src' => $solution->vendor->logo_path,'size' => 'sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->vendor->name),'src' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($solution->vendor->logo_path),'size' => 'sm']); ?>
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
                        <span class="min-w-0 truncate"><?php echo e($solution->vendor->name); ?></span>
                    </a>
                <?php endif; ?>

                <?php if($solution->description): ?>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-body"><?php echo e($solution->description); ?></p>
                <?php endif; ?>
            </div>

            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $solution)): ?>
                <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['href' => '#','variant' => 'glass','class' => 'shrink-0','dataAkPanelOpen' => true,'dataAkPanelUrl' => ''.e(route('solutions.edit', $solution)).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['href' => '#','variant' => 'glass','class' => 'shrink-0','data-ak-panel-open' => true,'data-ak-panel-url' => ''.e(route('solutions.edit', $solution)).'']); ?>
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

        
        <?php if($facts->isNotEmpty()): ?>
            <?php
                $factTones = [
                    'anchor'  => 'bg-accent text-white',
                    'green'   => 'bg-accent-soft text-accent ring-1 ring-accent-line',
                    'lime'    => 'bg-lime-soft text-lime-ink ring-1 ring-lime-line',
                    'amber'   => 'bg-hot-soft text-hot ring-1 ring-hot-line',
                    'crit'    => 'bg-crit-soft text-crit ring-1 ring-crit-line',
                    'neutral' => 'bg-raised text-body ring-1 ring-line-2',
                ];
                // Mesmas classes de tom acima, mas cada utility marcada `!`
                // importante — necessário só no `<select>` editável, pra
                // vencer o `bg-surface`/`text-ink`/`rounded-field` padrão do
                // `<x-forms.select>` (ver resources/views/components/forms/select.blade.php).
                // O `<span>` do viewer não precisa disso (sem componente pra
                // vencer), por isso os dois mapas.
                $important = fn (string $classes) => '!' . str_replace(' ', ' !', trim($classes));
                $canEditAttributes = \Illuminate\Support\Facades\Gate::allows('update', $solution);
            ?>
            <dl <?php if($canEditAttributes): ?> data-solution-attributes data-action="<?php echo e(route('solutions.attributes.update', $solution)); ?>" <?php endif; ?>
                class="grid grid-cols-2 gap-px border-t border-line bg-line sm:grid-cols-3 lg:grid-cols-4">
                <?php $__currentLoopData = $facts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $fact): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="bg-surface px-5 py-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted"><?php echo e($fact['label']); ?></dt>
                        <dd class="mt-1.5">
                            <?php if(! $canEditAttributes): ?>
                                
                                <?php if($fact['tone'] === 'plain'): ?>
                                    <span class="text-sm font-medium <?php echo e(filled($fact['value']) ? 'text-ink' : 'text-faint italic'); ?>"><?php echo e($fact['displayLabel'] ?: 'Não informado'); ?></span>
                                <?php elseif(blank($fact['value'])): ?>
                                    <span class="inline-flex items-center rounded-md border border-dashed border-line-2 px-2.5 py-1 text-xs font-medium text-faint">Não informado</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold <?php echo e($factTones[$fact['tone']] ?? $factTones['neutral']); ?>"><?php echo e($fact['displayLabel']); ?></span>
                                <?php endif; ?>
                            <?php elseif($fact['tone'] === 'plain'): ?>
                                <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => ''.e($fact['group']).'','title' => 'Editar '.e($fact['label']).'','dataAkSolutionAttribute' => true,'dataAkAttributeSelect' => ''.e($fact['group']).'','dataAkAttributeOptionsUrl' => ''.e(route('attribute-options.options', $fact['group'])).'','class' => '!h-8 !py-0 !text-sm']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => ''.e($fact['group']).'','title' => 'Editar '.e($fact['label']).'','data-ak-solution-attribute' => true,'data-ak-attribute-select' => ''.e($fact['group']).'','data-ak-attribute-options-url' => ''.e(route('attribute-options.options', $fact['group'])).'','class' => '!h-8 !py-0 !text-sm']); ?>
                                    <option value="" <?php if(blank($fact['value'])): echo 'selected'; endif; ?>>Não informado</option>
                                    <?php $__currentLoopData = $attributeOptions[$fact['group']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($option->value); ?>" <?php if($fact['value'] === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $attributes = $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $component = $__componentOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
                            <?php else: ?>
                                <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['name' => ''.e($fact['group']).'','title' => 'Editar '.e($fact['label']).'','dataAkSolutionAttribute' => true,'dataAkAttributeSelect' => ''.e($fact['group']).'','dataAkAttributeOptionsUrl' => ''.e(route('attribute-options.options', $fact['group'])).'','class' => '!h-[26px] !rounded-md !py-0 !pl-2.5 !pr-6 !text-xs !font-semibold '.e(blank($fact['value']) ? '!border !border-dashed !border-line-2 !bg-transparent !text-faint' : '!border-0 ' . $important($factTones[$fact['tone']] ?? $factTones['neutral'])).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => ''.e($fact['group']).'','title' => 'Editar '.e($fact['label']).'','data-ak-solution-attribute' => true,'data-ak-attribute-select' => ''.e($fact['group']).'','data-ak-attribute-options-url' => ''.e(route('attribute-options.options', $fact['group'])).'','class' => '!h-[26px] !rounded-md !py-0 !pl-2.5 !pr-6 !text-xs !font-semibold '.e(blank($fact['value']) ? '!border !border-dashed !border-line-2 !bg-transparent !text-faint' : '!border-0 ' . $important($factTones[$fact['tone']] ?? $factTones['neutral'])).'']); ?>
                                    <?php if($fact['nullable']): ?>
                                        <option value="" <?php if(blank($fact['value'])): echo 'selected'; endif; ?>>Não informado</option>
                                    <?php endif; ?>
                                    <?php $__currentLoopData = $attributeOptions[$fact['group']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($option->value); ?>" <?php if($fact['value'] === $option->value): echo 'selected'; endif; ?>><?php echo e($option->label); ?></option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $attributes = $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__attributesOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484)): ?>
<?php $component = $__componentOriginal7041cc63efd62f0450fe4bb37aadf484; ?>
<?php unset($__componentOriginal7041cc63efd62f0450fe4bb37aadf484); ?>
<?php endif; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </dl>
        <?php endif; ?>

        
        <div class="grid gap-x-6 gap-y-5 border-t border-line p-6 sm:grid-cols-3">
            <?php $__currentLoopData = ['Owner técnico' => $techOwners, 'Owner de negócio' => $businessOwners, 'Contato do fornecedor' => $vendorContacts]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $roleLabel => $group): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div>
                    <div class="text-[10px] font-semibold uppercase tracking-[0.09em] text-muted"><?php echo e($roleLabel); ?></div>
                    <?php $__empty_1 = true; $__currentLoopData = $group; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $person): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <a href="<?php echo e(route('people.show', $person)); ?>" class="mt-2 flex items-center gap-2.5 text-sm text-ink hover:text-accent">
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
                            <span class="min-w-0 truncate font-medium"><?php echo e($person->name); ?></span>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <p class="mt-2 flex items-center gap-2.5 text-sm text-faint">
                            <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-line-2">—</span>
                            <span>Não atribuído</span>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    <?php if($solution->support_operation_note): ?>
        <div class="mt-5 flex gap-3 rounded-card border border-crit-line bg-crit-soft p-4">
            <?php if (isset($component)) { $__componentOriginal643fe1b47aec0b76658e1a0200b34b2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal643fe1b47aec0b76658e1a0200b34b2c = $attributes; } ?>
<?php $component = BladeUI\Icons\Components\Svg::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('heroicon-o-exclamation-triangle'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\BladeUI\Icons\Components\Svg::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'size-5 shrink-0 text-crit']); ?>
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
            <div>
                <b class="text-sm text-ink">Suporte × operação</b>
                <p class="mt-0.5 text-sm text-muted"><?php echo e($solution->support_operation_note); ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/solutions/detail-header.blade.php ENDPATH**/ ?>