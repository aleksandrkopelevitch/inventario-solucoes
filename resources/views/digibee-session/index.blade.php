<x-layouts.layout title="Sessão Digibee">
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Integrações" title="Sessão Digibee"
            description="A sessão com que o servidor do iSol lê a Digibee. Ela vale 24 horas e é renovada entrando por esta tela." />
    </x-ui.hero-panel>

    <div class="space-y-6">
        <div class="rounded-card border border-line bg-raised/60 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">O que fica guardado</p>
            <ul class="mt-1.5 space-y-1 text-xs leading-relaxed text-muted">
                <li>· Só os dois cabeçalhos que a tela do Monitor da Digibee envia, criptografados no banco, e quem entrou.</li>
                <li>· A sessão carrega o papel inteiro da conta que entrou na Digibee. O servidor só faz as leituras de log que o iSol conhece.</li>
                <li>· Senha, código de MFA e o que mais for digitado aqui vão direto para a página de login e não ficam em lugar nenhum.</li>
            </ul>
        </div>

        <x-digibee.sign-in-panel />
    </div>
</x-layouts.layout>
