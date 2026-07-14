<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['type' => 'submit', 'variant' => 'primary', 'href' => null]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['type' => 'submit', 'variant' => 'primary', 'href' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Sistema de botões (3 papéis, hierarquia por peso — não por movimento):
    //   primary → verde sólido. ÚNICO verde. CTA principal (Salvar, Entrar…).
    //   glass   → neutro translúcido. Ação secundária (Editar, Gerenciar).
    //   ghost   → transparente, só ícone/texto. Ações de linha (lápis, lixeira,
    //             controles do canvas). Leve, sem herdar verde nem sombra.
    // Micro-interação contida: hover muda cor/sombra (sutil), clique afunda 1px.
    // Sem lift no hover, sem glow — nada exagerado.
    $variants = [
        'primary' => 'bg-accent text-white shadow-sm hover:bg-accent-press hover:shadow-md',
        'glass'   => 'border border-white/60 bg-white/55 text-ink shadow-sm ring-1 ring-line/70 backdrop-blur-md hover:bg-white/90 hover:ring-line-2',
        'ghost'   => 'text-muted hover:bg-raised hover:text-ink',
    ];

    // Com href renderiza <a> (link-botão, ex.: "Editar" abre o side-panel); senão <button>.
    $tag = $href ? 'a' : 'button';
?>

<<?php echo e($tag); ?>

    <?php if($href): ?> href="<?php echo e($href); ?>" <?php else: ?> type="<?php echo e($type); ?>" <?php endif; ?>
    <?php echo e($attributes->class([
        'relative inline-flex items-center justify-center gap-2 rounded-field px-4 py-2 text-sm font-semibold',
        'transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-out active:translate-y-px',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/35 focus-visible:ring-offset-2 focus-visible:ring-offset-surface',
        'disabled:opacity-60 disabled:cursor-not-allowed disabled:shadow-none',
        $variants[$variant] ?? $variants['primary'],
    ])); ?>

>
    <span data-spinner class="opacity-0 absolute">
        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </span>
    <span data-label class="flex items-center gap-1"><?php echo e($slot); ?></span>
</<?php echo e($tag); ?>>
<?php /**PATH /home/alexandre/inventario-solucoes/resources/views/components/forms/button.blade.php ENDPATH**/ ?>