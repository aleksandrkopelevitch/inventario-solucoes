{{-- Os tokens MCP já criados — e, no render logo depois de criar um, o token em
     texto claro.

     O bloco do token novo abre a lista em vez de aparecer num Toast por um
     motivo prático: ele precisa ser SELECIONADO e copiado, e um Toast some. Só
     existe neste render; qualquer atualização seguinte da lista não tem o valor
     para mostrar (ver App\View\Components\Mcp\TokenList). --}}
<div id="{{ $domId }}" class="space-y-4">

    @if ($plain)
        <div class="rounded-card border border-lime/50 bg-lime/[0.07] p-5 animate-ak-rise">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-lime/20 text-ink">
                    <x-heroicon-o-key class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="font-display text-base font-semibold text-ink">Token criado</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        Copie agora: este é o único momento em que ele aparece. O app guarda só um
                        resumo criptográfico — se perder, crie outro e apague este.
                    </p>

                    <div class="mt-3 flex items-center gap-2" data-ak-copy>
                        <x-forms.input type="text" readonly data-ak-copy-field
                            value="{{ $plain }}" class="!h-9 flex-1 font-mono !text-xs"
                            aria-label="Token MCP" />
                        <x-forms.button type="button" variant="ghost" data-ak-copy-trigger
                            data-ak-copy-message="Token copiado."
                            class="!h-9 !w-9 shrink-0 !p-0" aria-label="Copiar token">
                            <x-heroicon-o-clipboard-document class="size-5" />
                        </x-forms.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
        <div class="border-b border-line px-5 py-3.5">
            <h2 class="font-display text-base font-semibold text-ink">Tokens ativos</h2>
            <p class="mt-0.5 text-xs text-muted">
                {{ $tokens->count() }} {{ \Illuminate\Support\Str::plural('token', $tokens->count()) }} —
                apagar um token corta o acesso na hora, sem desfazer.
            </p>
        </div>

        <div class="divide-y divide-line">
            @forelse ($tokens as $token)
                <div class="group/row flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ $token->name }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                            <span class="font-mono text-faint">{{ $token->masked() }}</span>
                            <span aria-hidden="true" class="text-faint">·</span>
                            <span>criado {{ $token->created_at->translatedFormat('d/M/Y') }}</span>
                            @if ($token->createdBy)
                                <span>por {{ $token->createdBy->name }}</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2.5">
                        {{-- "Nunca usado" é a informação que decide se um token
                             pode ser apagado — e é o estado de todo token que
                             foi criado, colado no lugar errado e esquecido. --}}
                        <span @class([
                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                            'bg-raised text-muted' => $token->last_used_at,
                            'bg-warn-soft text-warn' => ! $token->last_used_at,
                        ])>
                            @if ($token->last_used_at)
                                usado {{ $token->last_used_at->diffForHumans() }}
                            @else
                                nunca usado
                            @endif
                        </span>

                        <x-ui.row-remove
                            :id="'mcp-token-delete-' . $token->id"
                            :action="route('mcp-tokens.destroy', $token)"
                            :confirm="'Apagar o token &quot;' . $token->name . '&quot;? Quem estiver usando esse token perde o acesso imediatamente.'"
                            :label="'Apagar token ' . $token->name" />
                    </div>
                </div>
            @empty
                {{-- Sem x-ui.empty-state de propósito: aquele componente exige
                     uma ilustração unDraw em resources/views/components/
                     illustrations/, e não existe nenhuma que sirva aqui.
                     Inventar uma meia-ilustração seria pior do que a moldura
                     tracejada, que é o que faz a lista vazia ler como
                     placeholder e não como card quebrado. --}}
                <div class="m-5 flex flex-col items-center gap-3 rounded-field border border-dashed border-line-2 px-4 py-8 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-raised text-muted">
                        <x-heroicon-o-key class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-ink">Nenhum token criado</p>
                        <p class="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-muted">
                            Crie um token para dar acesso a um programa sem navegador.
                        </p>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</div>
