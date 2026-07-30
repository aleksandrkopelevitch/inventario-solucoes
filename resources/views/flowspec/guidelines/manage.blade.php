<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div>
        <h2 class="font-display text-lg font-semibold text-ink">Gerenciar diretrizes</h2>
        <p class="mt-0.5 text-xs text-muted">Notas de boas práticas que o especialista sempre segue na geração — diferente das referências (selecionadas por tag), toda diretriz ativa entra em todo pedido. Não são checadas automaticamente como as regras de plataforma: são orientação, não regra rígida.</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<div class="max-h-[70vh] overflow-y-auto px-5 py-4">
    <x-flowspec.guideline-list />
</div>
