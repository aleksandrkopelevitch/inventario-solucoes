{{-- Um relatório coletado: enquanto roda, o status e o marcador do polling;
     pronto, o mesmo resumo que um chat recebe (App\Support\Digibee\Monitor\
     ReportDigest). O arquivo já foi redigido ao ser gravado. --}}
@php
    use App\Enums\DigibeeLogReportStatus;

    $ms = fn ($value) => \App\View\Components\Digibee\LogResults::duration($value) ?? '—';
@endphp
<div id="{{ $domId }}" class="space-y-6">
    @if (! $report->status->settled())
        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <p class="flex items-center gap-2 text-sm text-muted">
                <x-heroicon-o-arrow-path class="size-4 animate-spin" />
                {{ $report->status === DigibeeLogReportStatus::Pending ? 'Na fila para coletar…' : 'Coletando as execuções e lendo os logs de cada uma…' }}
                Pode levar alguns minutos; esta página atualiza sozinha.
            </p>
            <div data-ak-poll="{{ route('digibee-logs.reports.status', $report) }}" data-ak-poll-interval="4000" data-ak-poll-max="200"
                data-ak-poll-give-up="A coleta está demorando mais que o esperado — atualize a página para conferir."></div>
        </div>
    @elseif ($report->status === DigibeeLogReportStatus::Failed || $contents === null)
        <div class="rounded-card border border-hot-line bg-hot-soft p-5 text-sm text-hot">
            {{ $report->error ?? 'O relatório não pôde ser lido.' }}
        </div>
    @else
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-line bg-surface p-5 shadow-card text-sm">
            <span><strong class="font-display text-lg text-ink">{{ $contents['executions_found'] ?? 0 }}</strong> <span class="text-muted">encontradas</span></span>
            <span><strong class="font-display text-lg text-ink">{{ count($contents['executions'] ?? []) }}</strong> <span class="text-muted">abertas</span></span>
            @if (count($contents['not_opened'] ?? []))
                <span class="text-cat-amber-ink">{{ count($contents['not_opened']) }} não abertas (passou do limite)</span>
            @endif
            @if (count($contents['unreadable'] ?? []))
                <span class="text-hot">{{ count($contents['unreadable']) }} ilegíveis</span>
            @endif
            @foreach ($digest['levels'] as $level => $count)
                <span class="rounded-full bg-raised px-2 py-0.5 font-mono text-[11px] text-muted">{{ $level }} {{ $count }}</span>
            @endforeach
            <x-forms.button variant="ghost" :href="route('digibee-logs.reports.download', $report)" class="ml-auto !px-2.5 !py-1.5 text-xs">
                <x-heroicon-o-arrow-down-tray class="size-3.5" /> Baixar JSON
            </x-forms.button>
        </div>

        @if ($digest['by_pipeline'] !== [])
            <div class="overflow-x-auto rounded-card border border-line bg-surface shadow-card">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line text-xs text-muted">
                        <tr>
                            <th class="px-4 py-2 font-medium">Pipeline</th>
                            <th class="px-4 py-2 font-medium">Versões</th>
                            <th class="px-4 py-2 text-right font-medium">Execuções</th>
                            <th class="px-4 py-2 text-right font-medium">Com erro</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 font-medium">Duração (mediana / p95 / máx)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($digest['by_pipeline'] as $row)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="px-4 py-2 font-medium text-ink">{{ $row['pipeline'] }}</td>
                                <td class="px-4 py-2 text-muted">{{ implode(', ', $row['versions'] ?? []) }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['executions'] }}</td>
                                <td @class(['px-4 py-2 text-right', 'text-hot' => ($row['with_error'] ?? 0) > 0])>{{ $row['with_error'] ?? 0 }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-muted">
                                    @foreach ($row['statuses'] ?? [] as $status => $count)
                                        <span class="mr-2">{{ $status }}×{{ $count }}</span>
                                    @endforeach
                                </td>
                                <td class="px-4 py-2 text-muted">
                                    @if (isset($row['elapsed_ms']))
                                        {{ $ms($row['elapsed_ms']['median']) }} / {{ $ms($row['elapsed_ms']['p95']) }} / {{ $ms($row['elapsed_ms']['max']) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($digest['messages']['top'] !== [])
            <div class="rounded-card border border-line bg-surface shadow-card">
                <h2 class="border-b border-line px-5 py-3 font-display text-base font-semibold text-ink">
                    Erros e avisos <span class="text-sm font-normal text-muted">({{ $digest['messages']['distinct'] }} tipos)</span>
                </h2>
                @foreach ($digest['messages']['top'] as $message)
                    <div class="border-b border-line px-5 py-2.5 last:border-b-0">
                        <p class="text-xs text-muted">
                            <span @class(['font-semibold', 'text-hot' => in_array($message['level'], ['ERROR', 'FATAL']), 'text-cat-amber-ink' => ! in_array($message['level'], ['ERROR', 'FATAL'])])>{{ $message['level'] }}</span>
                            · {{ $message['count'] }}× em {{ $message['executions'] }} {{ $message['executions'] === 1 ? 'execução' : 'execuções' }}
                        </p>
                        <p class="mt-0.5 break-all font-mono text-[11px] leading-relaxed text-ink">{{ $message['example'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($digest['http_calls'] !== [])
            <div class="overflow-x-auto rounded-card border border-line bg-surface shadow-card">
                <h2 class="border-b border-line px-5 py-3 font-display text-base font-semibold text-ink">Chamadas HTTP</h2>
                <table class="w-full text-left text-sm">
                    <tbody>
                        @foreach ($digest['http_calls'] as $call)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="break-all px-4 py-2 font-mono text-xs text-ink">{{ $call['target'] }}</td>
                                <td @class(['px-4 py-2 font-mono text-xs', 'text-hot' => $call['status'] >= 400])>{{ $call['status'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $call['count'] }}×</td>
                                <td class="px-4 py-2 text-xs text-muted">mediana {{ $call['took_ms']['median'] ?? '—' }} ms</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="rounded-card border border-line bg-surface shadow-card">
            <h2 class="border-b border-line px-5 py-3 font-display text-base font-semibold text-ink">Linha do tempo</h2>
            @foreach ($digest['timeline'] as $execution)
                <div class="border-b border-line px-5 py-2.5 last:border-b-0">
                    <div class="flex flex-wrap items-center gap-x-3 text-sm">
                        <span class="font-mono text-xs text-muted">{{ \App\View\Components\Digibee\LogResults::clock($execution['at'] ?? null) }}</span>
                        <span class="font-medium text-ink">{{ $execution['pipeline'] }}</span>
                        @isset($execution['status'])
                            <span @class(['font-mono text-xs', 'text-hot' => $execution['status'] >= 400, 'text-muted' => $execution['status'] < 400])>{{ $execution['status'] }}</span>
                        @endisset
                        <x-forms.button type="button" variant="ghost" class="ml-auto !px-2 !py-0.5 text-xs"
                            data-ak-panel-open data-ak-panel-size="large"
                            data-ak-panel-url="{{ route('digibee-logs.executions.show', ['key' => $execution['key'], 'environment' => $report->environment]) }}">
                            Abrir log
                        </x-forms.button>
                    </div>
                    @foreach ($execution['notable_lines'] ?? [] as $line)
                        <p class="mt-0.5 break-all font-mono text-[11px] leading-relaxed text-muted">{{ $line }}</p>
                    @endforeach
                </div>
            @endforeach
            @if ($digest['timeline_note'])
                <p class="px-5 py-2.5 text-xs text-muted">
                    A linha do tempo mostra as primeiras {{ \App\Support\Digibee\Monitor\ReportDigest::TIMELINE_MAX }} execuções; as demais estão no JSON.
                </p>
            @endif
        </div>
    @endif
</div>
