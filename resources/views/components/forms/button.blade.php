@props(['type' => 'submit', 'variant' => 'primary', 'href' => null])

@php
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
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    {{ $attributes->class([
        'relative inline-flex items-center justify-center gap-2 rounded-field px-4 py-2 text-sm font-semibold',
        'transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-out active:translate-y-px',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/35 focus-visible:ring-offset-2 focus-visible:ring-offset-surface',
        'disabled:opacity-60 disabled:cursor-not-allowed disabled:shadow-none',
        $variants[$variant] ?? $variants['primary'],
    ]) }}
>
    <span data-spinner class="opacity-0 absolute">
        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </span>
    <span data-label class="flex items-center gap-1">{{ $slot }}</span>
</{{ $tag }}>
