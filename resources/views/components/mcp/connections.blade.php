{{-- As conexões MCP que esta conta autorizou. Vazio é o estado normal de quem
     ainda não conectou nada — por isso a moldura tracejada, e não um card que
     parece quebrado (mesma escolha de components/mcp/token-list). --}}
<div id="{{ $domId }}" class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <div class="border-b border-line px-5 py-3.5">
        <h2 class="font-display text-base font-semibold text-ink">Suas conexões</h2>
        <p class="mt-0.5 text-xs text-muted">
            Os aplicativos que você autorizou a ler o inventário com a sua conta.
            Revogar corta o acesso na hora.
        </p>
    </div>

    <div class="divide-y divide-line">
        @forelse ($connections as $connection)
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink">{{ $connection->client?->name ?? 'Cliente desconhecido' }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                        <span>autorizado {{ $connection->created_at->translatedFormat('d/M/Y') }}</span>
                        @if ($connection->expires_at)
                            <span aria-hidden="true" class="text-faint">·</span>
                            <span>expira {{ $connection->expires_at->diffForHumans() }}</span>
                        @endif
                    </p>
                </div>

                <x-ui.row-remove
                    :id="'mcp-connection-revoke-' . $connection->id"
                    :action="route('mcp.connections.destroy', $connection->id)"
                    confirm="Revogar esta conexão? O aplicativo vai pedir autorização de novo na próxima vez."
                    :label="'Revogar conexão ' . ($connection->client?->name ?? '')" />
            </div>
        @empty
            <div class="m-5 flex flex-col items-center gap-3 rounded-field border border-dashed border-line-2 px-4 py-8 text-center">
                <span class="flex size-10 items-center justify-center rounded-full bg-raised text-muted">
                    <x-heroicon-o-bolt class="size-5" />
                </span>
                <div>
                    <p class="text-sm font-medium text-ink">Nenhuma conexão ainda</p>
                    <p class="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-muted">
                        Siga os passos acima no Claude Desktop — a conexão aparece aqui
                        assim que você autorizar.
                    </p>
                </div>
            </div>
        @endforelse
    </div>
</div>
