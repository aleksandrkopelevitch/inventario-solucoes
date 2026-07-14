<x-layouts.layout :title="$solution->name">
    <div class="mb-6">
        <a href="{{ route('solutions.index') }}" class="text-sm text-accent hover:underline">&larr; Soluções</a>
    </div>

    {{-- 1/2. Header + bloco "Operação" (Solutions\DetailHeader, atualizável) --}}
    <x-solutions.detail-header :solution="$solution" />

    {{-- 3. Integrações (F3) — lista das integrações à esquerda (criar/excluir,
         selecionável); à direita, a visualização gráfica da integração
         selecionada, que também autora blocos/ligações/protocolo e
         renome/status (lápis da topbar). --}}
    <div class="mt-5 rounded-card border border-line bg-surface p-6 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
        <div class="flex items-baseline gap-2.5">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">F3</span>
            <h2 class="font-display text-[22px] font-semibold text-ink">Integrações</h2>
        </div>
        <p class="mt-1 text-sm text-muted">Selecione uma integração à esquerda para ver a visualização gráfica.</p>

        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-stretch">
            {{-- Esquerda: lista das integrações da solução --}}
            <div class="w-full shrink-0 lg:w-2/5 lg:max-w-sm">
                <x-solutions.integrations-map :solution="$solution" />
            </div>

            {{-- Direita: visualização gráfica (pan/zoom + tela cheia) da integração selecionada --}}
            <x-solutions.integration-viz />
        </div>
    </div>

    {{-- 4. Documentação rica da solução (editor de blocos, read-only aqui).
         A cobertura de documentação (antigo bloco F7) virou o menu "..." no
         cabeçalho da sub-página /documentacao — não mora mais aqui. --}}
    <x-solutions.documentation :solution="$solution" />
</x-layouts.layout>
