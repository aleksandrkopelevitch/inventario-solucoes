<?php if (isset($component)) { $__componentOriginalf2b16bc3883246ba4659aff94e382522 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf2b16bc3883246ba4659aff94e382522 = $attributes; } ?>
<?php $component = App\View\Components\Layouts\Layout::resolve(['title' => 'Componentes'] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\Layouts\Layout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Design system</span>
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Componentes de formulário</h1>
            <p class="mt-1 text-sm text-muted">Identidade Leo Madeiras — verde institucional e lime, tipografia Barlow.</p>
        </div>

        <div class="space-y-6 rounded-card border border-line bg-surface p-8 text-body shadow-[0_1px_3px_rgba(20,58,34,0.04),0_14px_32px_-16px_rgba(20,58,34,0.12)]">
            <form id="showcase-form" class="space-y-6">
                <?php echo csrf_field(); ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Input','for' => 'sc-input','name' => 'input','hint' => 'x-forms.input dentro de x-forms.field']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Input','for' => 'sc-input','name' => 'input','hint' => 'x-forms.input dentro de x-forms.field']); ?>
                    <?php if (isset($component)) { $__componentOriginal4fb6044c7ed6b655352043ff774efcd0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.input','data' => ['id' => 'sc-input','name' => 'input','placeholder' => 'Digite algo']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'sc-input','name' => 'input','placeholder' => 'Digite algo']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $attributes = $__attributesOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__attributesOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0)): ?>
<?php $component = $__componentOriginal4fb6044c7ed6b655352043ff774efcd0; ?>
<?php unset($__componentOriginal4fb6044c7ed6b655352043ff774efcd0); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Select','for' => 'sc-select','name' => 'select']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Select','for' => 'sc-select','name' => 'select']); ?>
                    <?php if (isset($component)) { $__componentOriginal7041cc63efd62f0450fe4bb37aadf484 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7041cc63efd62f0450fe4bb37aadf484 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.select','data' => ['id' => 'sc-select','name' => 'select']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'sc-select','name' => 'select']); ?>
                        <option value="">Selecione…</option>
                        <option value="a">Opção A</option>
                        <option value="b">Opção B</option>
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
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Textarea','for' => 'sc-textarea','name' => 'textarea']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Textarea','for' => 'sc-textarea','name' => 'textarea']); ?>
                    <?php if (isset($component)) { $__componentOriginalea7b7095850fe8bc9657025b89ccf5a5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.textarea','data' => ['id' => 'sc-textarea','name' => 'textarea','rows' => '3','placeholder' => 'Texto longo']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.textarea'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'sc-textarea','name' => 'textarea','rows' => '3','placeholder' => 'Texto longo']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5)): ?>
<?php $attributes = $__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5; ?>
<?php unset($__attributesOriginalea7b7095850fe8bc9657025b89ccf5a5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalea7b7095850fe8bc9657025b89ccf5a5)): ?>
<?php $component = $__componentOriginalea7b7095850fe8bc9657025b89ccf5a5; ?>
<?php unset($__componentOriginalea7b7095850fe8bc9657025b89ccf5a5); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'File','for' => 'sc-file','name' => 'file']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'File','for' => 'sc-file','name' => 'file']); ?>
                    <?php if (isset($component)) { $__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.file','data' => ['id' => 'sc-file','name' => 'file']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.file'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'sc-file','name' => 'file']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c)): ?>
<?php $attributes = $__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c; ?>
<?php unset($__attributesOriginal3e903a5b1d9ce9261a6e2c147356ad2c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c)): ?>
<?php $component = $__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c; ?>
<?php unset($__componentOriginal3e903a5b1d9ce9261a6e2c147356ad2c); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Checkbox']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Checkbox']); ?>
                    <label class="flex items-center gap-2 text-sm text-body">
                        <?php if (isset($component)) { $__componentOriginala6191e173a29d7cb3002715ea2e926a8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala6191e173a29d7cb3002715ea2e926a8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.checkbox','data' => ['name' => 'checkbox']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.checkbox'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'checkbox']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala6191e173a29d7cb3002715ea2e926a8)): ?>
<?php $attributes = $__attributesOriginala6191e173a29d7cb3002715ea2e926a8; ?>
<?php unset($__attributesOriginala6191e173a29d7cb3002715ea2e926a8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala6191e173a29d7cb3002715ea2e926a8)): ?>
<?php $component = $__componentOriginala6191e173a29d7cb3002715ea2e926a8; ?>
<?php unset($__componentOriginala6191e173a29d7cb3002715ea2e926a8); ?>
<?php endif; ?> Aceito os termos
                    </label>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Radio group']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Radio group']); ?>
                    <?php if (isset($component)) { $__componentOriginal6a25e3c508dc11ad6a013831f38310b8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6a25e3c508dc11ad6a013831f38310b8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.radio-group','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.radio-group'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
                        <?php if (isset($component)) { $__componentOriginal7edb3b69061800e185445c9bae1bdd4f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.radio','data' => ['name' => 'radio','value' => 'sim']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.radio'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'radio','value' => 'sim']); ?>Sim <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $attributes = $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $component = $__componentOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?>
                        <?php if (isset($component)) { $__componentOriginal7edb3b69061800e185445c9bae1bdd4f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.radio','data' => ['name' => 'radio','value' => 'nao']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.radio'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'radio','value' => 'nao']); ?>Não <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $attributes = $__attributesOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__attributesOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f)): ?>
<?php $component = $__componentOriginal7edb3b69061800e185445c9bae1bdd4f; ?>
<?php unset($__componentOriginal7edb3b69061800e185445c9bae1bdd4f); ?>
<?php endif; ?>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6a25e3c508dc11ad6a013831f38310b8)): ?>
<?php $attributes = $__attributesOriginal6a25e3c508dc11ad6a013831f38310b8; ?>
<?php unset($__attributesOriginal6a25e3c508dc11ad6a013831f38310b8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6a25e3c508dc11ad6a013831f38310b8)): ?>
<?php $component = $__componentOriginal6a25e3c508dc11ad6a013831f38310b8; ?>
<?php unset($__componentOriginal6a25e3c508dc11ad6a013831f38310b8); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <hr class="border-line">
                <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-muted">Novos (Etapa 0)</p>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Toggle (booleano)']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Toggle (booleano)']); ?>
                    <?php if (isset($component)) { $__componentOriginal6c7b09ecb327498caf5d3513503a0831 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6c7b09ecb327498caf5d3513503a0831 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.toggle','data' => ['name' => 'toggle']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.toggle'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'toggle']); ?>Ativo <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6c7b09ecb327498caf5d3513503a0831)): ?>
<?php $attributes = $__attributesOriginal6c7b09ecb327498caf5d3513503a0831; ?>
<?php unset($__attributesOriginal6c7b09ecb327498caf5d3513503a0831); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6c7b09ecb327498caf5d3513503a0831)): ?>
<?php $component = $__componentOriginal6c7b09ecb327498caf5d3513503a0831; ?>
<?php unset($__componentOriginal6c7b09ecb327498caf5d3513503a0831); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Image upload']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Image upload']); ?>
                    <?php if (isset($component)) { $__componentOriginal2624037fcded6657b98353ea333c8c18 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2624037fcded6657b98353ea333c8c18 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.image-upload','data' => ['name' => 'imagem']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.image-upload'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'imagem']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2624037fcded6657b98353ea333c8c18)): ?>
<?php $attributes = $__attributesOriginal2624037fcded6657b98353ea333c8c18; ?>
<?php unset($__attributesOriginal2624037fcded6657b98353ea333c8c18); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2624037fcded6657b98353ea333c8c18)): ?>
<?php $component = $__componentOriginal2624037fcded6657b98353ea333c8c18; ?>
<?php unset($__componentOriginal2624037fcded6657b98353ea333c8c18); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <?php if (isset($component)) { $__componentOriginal788c5626c9f4f85906027b3ea3343fab = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal788c5626c9f4f85906027b3ea3343fab = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.field','data' => ['label' => 'Chips (seleção múltipla com papel)','hint' => 'Digite e pressione Enter para adicionar']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'Chips (seleção múltipla com papel)','hint' => 'Digite e pressione Enter para adicionar']); ?>
                    <?php if (isset($component)) { $__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.chips','data' => ['name' => 'responsaveis','items' => [['value' => 'ana', 'label' => 'Ana', 'role' => 'owner']],'roles' => [['value' => 'owner', 'label' => 'Responsável'], ['value' => 'contributor', 'label' => 'Colaborador']]]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.chips'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'responsaveis','items' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['value' => 'ana', 'label' => 'Ana', 'role' => 'owner']]),'roles' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute([['value' => 'owner', 'label' => 'Responsável'], ['value' => 'contributor', 'label' => 'Colaborador']])]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8)): ?>
<?php $attributes = $__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8; ?>
<?php unset($__attributesOriginalf6d00911ab92bb8c5e45a70991c07ef8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8)): ?>
<?php $component = $__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8; ?>
<?php unset($__componentOriginalf6d00911ab92bb8c5e45a70991c07ef8); ?>
<?php endif; ?>
                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $attributes = $__attributesOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__attributesOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal788c5626c9f4f85906027b3ea3343fab)): ?>
<?php $component = $__componentOriginal788c5626c9f4f85906027b3ea3343fab; ?>
<?php unset($__componentOriginal788c5626c9f4f85906027b3ea3343fab); ?>
<?php endif; ?>

                <div class="pt-2">
                    <?php if (isset($component)) { $__componentOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal48c3958713aa2b1d2dd1900fbfcfc804 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.forms.button','data' => ['type' => 'button']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('forms.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'button']); ?>Botão <?php echo $__env->renderComponent(); ?>
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
            </form>
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
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/showcase.blade.php ENDPATH**/ ?>