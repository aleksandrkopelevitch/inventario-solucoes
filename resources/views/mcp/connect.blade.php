{{-- Connecting a chat app to the inventory — the screen for somebody who is
     NOT technical.

     Not /mcp-tokens: that one mints a token that reads the whole catalog and
     belongs to an admin. This one belongs to whoever is doing the connecting,
     any account included. --}}
<x-layouts.layout title="Conexão MCP">
    <x-ui.hero-panel compact class="mb-6">
        <div>
            <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
                <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
                Integrações
            </span>
            <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">
                Conectar ao Claude
            </h1>
            <p class="mt-1 max-w-2xl text-sm text-[color:var(--color-glow-ink)]/70">
                Deixa o Claude (ou o ChatGPT) consultar o inventário direto na conversa:
                soluções, diagramas, pessoas, empresas e a documentação publicada.
                Somente leitura, com a sua conta Leo.
            </p>
        </div>
    </x-ui.hero-panel>

    <div class="space-y-6">

        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Passo 1 — copie o endereço</h2>
            <p class="mt-0.5 text-xs text-muted">
                É a única coisa que você precisa. Não há token para copiar nem arquivo para editar.
            </p>

            <x-ui.copy-field class="mt-3" :value="$endpoint" mono label="Endereço do servidor MCP"
                message="Endereço copiado." copy-label="Copiar endereço" />
        </div>

        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Passo 2 — adicione o conector</h2>

            <div class="mt-3 space-y-4">
                <div>
                    <p class="text-sm font-semibold text-ink">Claude Desktop</p>
                    <ol class="mt-1.5 space-y-1 text-[13px] leading-relaxed text-body">
                        <li>1. Menu <strong class="font-semibold text-ink">Configurações</strong> → <strong class="font-semibold text-ink">Conectores</strong>.</li>
                        <li>2. <strong class="font-semibold text-ink">Adicionar conector personalizado</strong>.</li>
                        <li>3. Cole o endereço do passo 1 e confirme.</li>
                        <li>4. O Claude abre uma janela do Inventário: entre com sua conta Leo e clique em <strong class="font-semibold text-ink">Autorizar</strong>.</li>
                    </ol>
                </div>

                <div>
                    <p class="text-sm font-semibold text-ink">ChatGPT</p>
                    <p class="mt-1.5 text-[13px] leading-relaxed text-body">
                        Em <strong class="font-semibold text-ink">Configurações</strong> →
                        <strong class="font-semibold text-ink">Conectores</strong>, adicione um conector
                        MCP com o mesmo endereço. A autorização é igual: entrar com a conta Leo e autorizar.
                    </p>
                </div>
            </div>

            <div class="mt-4 rounded-field border border-line bg-raised/60 px-3.5 py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">O que a conexão enxerga</p>
                <ul class="mt-1.5 space-y-1 text-xs leading-relaxed text-muted">
                    @if (auth()->user()->canView(\App\Enums\AccessModule::Catalog))
                        <li>· O catálogo: soluções, pessoas (com contatos) e empresas.</li>
                    @endif
                    @if (auth()->user()->canView(\App\Enums\AccessModule::Documentation))
                        <li>· Os diagramas.</li>
                    @endif
                    <li>· Da documentação, só os cadernos publicados. Caderno não publicado é invisível.</li>
                    <li>· Valores protegidos nunca saem — o modelo vê só que existe um.</li>
                    <li>· Nada é criado, alterado ou apagado por aqui.</li>
                </ul>
            </div>
        </div>

        <x-mcp.connections />

        @can('viewAny', \App\Models\McpToken::class)
            {{-- The technical path sits at the end, and as a link: whoever
                 needs it knows they do, and whoever does not should never
                 trip over a token. --}}
            <div class="rounded-card border border-line bg-surface p-5 shadow-card">
                <h2 class="font-display text-base font-semibold text-ink">Conectar um programa</h2>
                <p class="mt-0.5 text-xs leading-relaxed text-muted">
                    Scripts, CI e clientes sem navegador não têm como passar pela tela de
                    autorização. Para esses existe o token fixo, que continua sendo
                    administrado em
                    <a href="{{ route('mcp-tokens.index') }}" class="text-accent hover:underline">Tokens MCP</a>.
                </p>
            </div>
        @endcan
    </div>
</x-layouts.layout>
