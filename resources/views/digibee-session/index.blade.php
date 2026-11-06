<x-layouts.layout title="Sessão Digibee">
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Integrações" title="Sessão Digibee"
            description="A sessão com que o servidor do iSol lê a Digibee. Ela vale 24 horas e é renovada a partir do seu próprio navegador." />
    </x-ui.hero-panel>

    <div class="space-y-6">
        <x-digibee.session-card />

        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Enviar a sessão do seu navegador</h2>
            <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted">
                <li>
                    Arraste este botão para a barra de favoritos (só na primeira vez):
                    {{-- Um link (x-forms.button com href), porque o que importa
                         é poder ARRASTÁ-LO para a barra de favoritos. Clicado
                         aqui ele só avisa que precisa estar na Digibee. --}}
                    <x-forms.button :href="$bookmarklet" draggable="true" title="Arraste para a barra de favoritos"
                        class="ml-1 !px-3 !py-1 text-xs">
                        <x-heroicon-o-bookmark class="size-3.5" />
                        Enviar sessão ao iSol
                    </x-forms.button>
                </li>
                <li>
                    Entre na <a href="{{ config('services.digibee.monitor.app_url') }}" target="_blank" rel="noopener" class="text-accent hover:underline">Digibee</a>
                    normalmente, com a sua conta Leo.
                </li>
                <li>Com a Digibee aberta, clique no favorito. Uma janela do iSol abre, recebe a sessão e mostra até quando ela vale.</li>
            </ol>
            <p class="mt-3 text-xs text-muted">
                Se a barra de favoritos estiver escondida, mostre com Ctrl+Shift+B. Repita os passos 2 e 3 quando a sessão expirar.
            </p>
        </div>

        <div class="rounded-card border border-line bg-raised/60 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">O que o favorito envia</p>
            <ul class="mt-1.5 space-y-1 text-xs leading-relaxed text-muted">
                <li>· Só o token de sessão que a Digibee guarda no seu navegador e a chave pública do app dela. Nada de senha.</li>
                <li>· Antes de guardar, o iSol confere que a sessão é do realm da Leo, que ainda vale e que lê o Monitor de produção.</li>
                <li>· Ela fica criptografada no banco e carrega o papel inteiro da sua conta na Digibee. O servidor só faz as leituras de log que o iSol conhece.</li>
            </ul>
        </div>
    </div>
</x-layouts.layout>
