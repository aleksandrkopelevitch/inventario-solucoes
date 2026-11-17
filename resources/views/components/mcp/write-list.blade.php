{{-- O que os chats mudaram pelo MCP, do mais recente ao mais antigo, cada um com
     o seu antes/depois e um "Reverter". As linhas chegam prontas do componente
     (App\View\Components\Mcp\WriteList) — aqui só se desenha. --}}
<div id="{{ $domId }}" class="space-y-4">

    <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3.5">
            <div>
                <h2 class="font-display text-base font-semibold text-ink">Alterações</h2>
                <p class="mt-0.5 text-xs text-muted">
                    {{ $total }} {{ $total === 1 ? 'alteração' : 'alterações' }}
                    @if ($pending !== $total)
                        · {{ $pending }} sem reverter
                    @endif
                    @if ($total > count($rows))
                        · mostrando as {{ count($rows) }} mais recentes
                    @endif
                </p>
            </div>

            {{-- Reverter em lote só com filtro: sem ele, o botão desfaria tudo o
                 que todo chat já fez, o que nunca é o que alguém quer depois de
                 UMA conversa ruim. A request recusa o mesmo, por garantia. --}}
            @if ($filters !== [] && $pending > 0)
                <form id="mcp-writes-revert-selection" class="hidden">
                    @csrf
                </form>
                <x-forms.button variant="ghost" class="!text-crit hover:!bg-crit-soft"
                    data-ak-ajax="mcp-writes-revert-selection"
                    data-ak-action="{{ route('mcp-writes.revert-selection', ['filter' => $filters]) }}"
                    data-ak-confirm="Reverter as {{ $pending }} alterações deste filtro? As que foram mudadas depois por outra pessoa são puladas.">
                    Reverter seleção ({{ $pending }})
                </x-forms.button>
            @endif
        </div>

        <div class="divide-y divide-line">
            @forelse ($rows as $row)
                @php($write = $row['write'])
                <div class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-ink">
                                <span class="font-medium">{{ $row['tool'] }}</span>
                                <span class="text-muted">·</span>
                                <span class="text-muted">{{ $row['subject']['type'] }}</span>
                                @if ($row['subject']['url'])
                                    <a href="{{ $row['subject']['url'] }}" class="font-medium text-accent hover:underline">{{ $row['subject']['name'] }}</a>
                                @else
                                    <span class="italic text-muted">{{ $row['subject']['name'] }}</span>
                                @endif
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                                <span>{{ $write->user?->name ?? 'conta removida' }}</span>
                                @if ($row['client'])
                                    <span aria-hidden="true" class="text-faint">·</span>
                                    <span>via {{ $row['client'] }}</span>
                                @endif
                                <span aria-hidden="true" class="text-faint">·</span>
                                <span title="{{ $write->created_at->timezone(config('app.display_timezone', config('app.timezone')))->format('d/m/Y H:i') }}">{{ $write->created_at->diffForHumans() }}</span>
                            </p>
                        </div>

                        <div class="shrink-0">
                            @if ($write->isReverted())
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[11px] font-medium text-muted">
                                    revertida{{ $write->revertedBy ? ' por ' . $write->revertedBy->name : '' }}
                                    {{ $write->reverted_at->diffForHumans() }}
                                </span>
                            @else
                                <form id="mcp-write-revert-{{ $write->id }}" class="hidden">
                                    @csrf
                                </form>
                                <x-forms.button variant="ghost" class="!px-3 !py-1 !text-xs"
                                    data-ak-ajax="mcp-write-revert-{{ $write->id }}"
                                    data-ak-action="{{ route('mcp-writes.revert', ['mcpWrite' => $write, 'filter' => $filters]) }}"
                                    data-ak-confirm="{{ $write->isCreate() ? 'Reverter este cadastro exclui o registro. Continuar?' : 'Voltar estes campos ao valor anterior?' }}">
                                    Reverter
                                </x-forms.button>
                            @endif
                        </div>
                    </div>

                    <dl class="mt-3 grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] gap-x-4 gap-y-1.5 rounded-field bg-raised/50 px-3.5 py-2.5 text-xs">
                        @foreach ($row['diff'] as $change)
                            <dt class="truncate font-medium text-muted">{{ $change['field'] }}</dt>
                            <dd class="min-w-0 break-words text-body">
                                @if (! $write->isCreate())
                                    <span class="text-crit line-through decoration-crit/40">{{ $change['before'] ?? 'vazio' }}</span>
                                    <span aria-hidden="true" class="mx-1 text-faint">→</span>
                                @endif
                                <span class="text-ink">{{ $change['after'] ?? 'vazio' }}</span>
                            </dd>
                        @endforeach
                    </dl>
                </div>
            @empty
                <div class="m-5 flex flex-col items-center gap-3 rounded-field border border-dashed border-line-2 px-4 py-8 text-center">
                    <span class="flex size-10 items-center justify-center rounded-full bg-raised text-muted">
                        <x-heroicon-o-clock class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-ink">Nenhuma alteração</p>
                        <p class="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-muted">
                            {{ $filters === [] ? 'Nenhum chat alterou nada pelo MCP ainda.' : 'Nada com estes filtros.' }}
                        </p>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</div>
