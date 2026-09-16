<x-layouts.layout title="Tokens MCP">
    <x-ui.hero-panel compact class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
                    <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
                    Integrações
                </span>
                <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">
                    Tokens MCP
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-[color:var(--color-glow-ink)]/70">
                    Credenciais fixas para programas sem navegador. Quem vai conectar um app de
                    chat não precisa de token — usa a própria conta.
                </p>
            </div>
        </div>
    </x-ui.hero-panel>

    <div class="space-y-6">

        {{-- Antes da lista, e agora com uma pergunta a mais para responder: desde
             o OAuth, a maioria de quem chega aqui não precisa de token nenhum.
             Mandar essa pessoa embora logo é mais útil do que explicar o token
             para quem nunca vai colar um. --}}
        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Token ou conta?</h2>
            <p class="mt-0.5 text-xs leading-relaxed text-muted">
                Para conectar o <strong class="font-semibold text-ink">Claude Desktop</strong>, o ChatGPT ou
                qualquer app de chat, ninguém precisa de token: a pessoa cola o endereço, entra com a
                conta Leo e autoriza — veja
                <a href="{{ route('mcp.connect') }}" class="text-accent hover:underline">Conexão MCP</a>.
                A conexão lê o que a conta dela lê.
            </p>
            <p class="mt-2 text-xs leading-relaxed text-muted">
                O token desta tela é para o que <strong class="font-semibold text-ink">não tem navegador</strong>
                para autorizar: um script, um job de CI, um agente rodando sozinho. Ele não é uma conta —
                lê o catálogo inteiro, não expira e vale para quem tiver o arquivo onde ele estiver.
            </p>

            <p class="mt-4 text-[11px] font-medium text-muted">Endereço do servidor</p>
            <div class="mt-1.5 flex items-center gap-2" data-ak-copy>
                <x-forms.input type="text" readonly data-ak-copy-field
                    value="{{ $endpoint }}" class="!h-9 flex-1 font-mono !text-xs"
                    aria-label="Endereço do servidor MCP" />
                <x-forms.button type="button" variant="ghost" data-ak-copy-trigger
                    data-ak-copy-message="Endereço copiado."
                    class="!h-9 !w-9 shrink-0 !p-0" aria-label="Copiar endereço">
                    <x-heroicon-o-clipboard-document class="size-5" />
                </x-forms.button>
            </div>

            <p class="mt-4 text-[11px] font-medium text-muted">Claude Code (terminal)</p>
            <pre class="mt-1.5 overflow-x-auto rounded-field bg-raised px-3 py-2.5 font-mono text-[11px] leading-relaxed text-ink"><code>claude mcp add --transport http inventario {{ $endpoint }} \
  --header "Authorization: Bearer SEU_TOKEN"</code></pre>
            <p class="mt-1.5 text-xs leading-relaxed text-muted">
                Sem o <code class="rounded bg-raised px-1 py-0.5 font-mono text-[11px]">--header</code>, o
                Claude Code também conecta pela conta: ele abre o navegador na tela de autorização.
            </p>

            {{-- O que o token alcança, dito onde ele é entregue. A pergunta
                 "posso mandar isso pra fulano?" se responde aqui ou não se
                 responde em lugar nenhum. --}}
            <div class="mt-4 rounded-field border border-line bg-raised/60 px-3.5 py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">O que o token alcança</p>
                <ul class="mt-1.5 space-y-1 text-xs leading-relaxed text-muted">
                    <li>· Todo o catálogo: soluções, diagramas, pessoas (com contatos) e empresas.</li>
                    <li>· Da documentação, <strong class="font-semibold text-ink">só os cadernos publicados</strong> em
                        <a href="{{ route('docs.settings') }}" class="text-accent hover:underline">/docs</a>.
                        Caderno não publicado é invisível, inclusive para quem tem o token.</li>
                    <li>· Valores protegidos (<code class="font-mono text-[11px]">{% secret %}</code>) nunca saem — o modelo vê só que existe um.</li>
                    <li>· Nada é criado, alterado ou apagado por aqui.</li>
                </ul>
            </div>
        </div>

        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Novo token</h2>
            <p class="mt-0.5 text-xs text-muted">
                Dê um nome que diga onde ele vai ficar — "APLA (agente de integrações)",
                "CI do inventário". É o que permite apagar o certo depois.
            </p>

            <form id="mcp-token-form" class="mt-3 flex flex-wrap items-center gap-1.5">
                @csrf
                <x-forms.input name="name" placeholder="Nome do token" class="min-w-48 flex-1 !py-1.5 text-sm" />
                <x-forms.button data-ak-ajax="mcp-token-form" data-ak-action="{{ route('mcp-tokens.store') }}"
                    class="!shrink-0 !px-2.5 !py-1.5 text-xs">
                    Criar token
                </x-forms.button>
            </form>
        </div>

        <x-mcp.token-list />
    </div>
</x-layouts.layout>
