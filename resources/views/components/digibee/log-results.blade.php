{{-- O que o “Buscar” encontrou: execuções NÃO abertas, mais novas primeiro.
     Cada linha abre o log inteiro no painel lateral. Trechos já redigidos
     (App\View\Components\Digibee\LogResults). --}}
@php
    $labels = ['lines' => 'Linhas de log', 'payloads' => 'Payloads', 'executions' => 'Execuções'];
@endphp
<div id="{{ $domId }}">
    @if ($query === null)
        <p class="px-1 text-sm text-muted">Preencha um termo ou uma pipeline e clique em Buscar.</p>
    @else
        <div class="rounded-card border border-line bg-surface shadow-card">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-5 py-3 text-xs text-muted">
                <span class="font-display text-base font-semibold text-ink">{{ count($rows) }} {{ count($rows) === 1 ? 'execução' : 'execuções' }}</span>
                @foreach ($coverage as $source => $info)
                    <span>{{ $labels[$source] ?? $source }}: {{ $info['matches'] }} encontrad{{ $source === 'lines' ? 'as' : 'os' }}@if ($info['platform_total'] !== null) de {{ $info['platform_total'] }}@endif</span>
                @endforeach
                <span>{{ $query->window()['from'] }} – {{ $query->window()['to'] }}</span>
            </div>

            @if (collect($coverage)->contains(fn ($info) => $info['more_in_window']))
                <p class="border-b border-line bg-cat-amber-soft px-5 py-2 text-xs text-cat-amber-ink">
                    Há mais resultados no período do que esta lista mostra. Diminua o período ou gere o relatório com todas.
                </p>
            @endif

            @forelse ($rows as $row)
                <div class="flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-line px-5 py-2.5 text-sm last:border-b-0">
                    <span class="w-28 shrink-0 font-mono text-xs text-muted">{{ $row['at'] }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink">
                            {{ $row['pipeline'] }}
                            @if ($row['status'] !== null)
                                <span @class(['ml-1 font-mono text-xs', 'text-hot' => $row['status'] >= 400, 'text-muted' => $row['status'] < 400])>{{ $row['status'] }}</span>
                            @endif
                            @if ($row['elapsed'])
                                <span class="ml-1 text-xs text-muted">{{ $row['elapsed'] }}</span>
                            @endif
                        </p>
                        @if ($row['excerpt'])
                            <p class="mt-0.5 break-all font-mono text-[11px] leading-relaxed text-muted">{{ $row['excerpt'] }}</p>
                        @endif
                    </div>
                    <x-forms.button type="button" variant="ghost" class="!px-2 !py-1 text-xs"
                        data-ak-panel-open data-ak-panel-size="large"
                        data-ak-panel-url="{{ route('digibee-logs.executions.show', ['key' => $row['key'], 'environment' => $query->environment]) }}">
                        Abrir log
                    </x-forms.button>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-muted">Nada encontrado nesse período. Lembre que os logs ficam 10 dias em produção e 3 em teste.</p>
            @endforelse
        </div>
    @endif
</div>
