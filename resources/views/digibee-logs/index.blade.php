<x-layouts.layout title="Logs Digibee">
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Integrações" title="Logs Digibee"
            description="Busque execuções das pipelines da Digibee por pedido, SKU, CNPJ ou mensagem de erro, abra o log de cada uma ou gere um relatório com todas." />
    </x-ui.hero-panel>

    <div class="space-y-6">
        @unless ($available)
            <div class="rounded-card border border-hot-line bg-hot-soft px-4 py-3 text-sm text-hot">
                A sessão que o iSol usa para ler a Digibee expirou. Um administrador renova em
                @can('viewAny', \App\Models\DigibeeMonitorSession::class)
                    <a href="{{ route('digibee-session.index') }}" class="font-semibold underline">Sessão Digibee</a>.
                @else
                    “Sessão Digibee” — os administradores são avisados quando alguém tenta buscar.
                @endcan
            </div>
        @endunless

        <form id="digibee-logs-form" class="rounded-card border border-line bg-surface p-5 shadow-card">
            @csrf
            <div class="grid gap-4 md:grid-cols-6">
                <x-forms.field label="Termo" name="term" hint="Pedido, SKU, CNPJ, texto de erro…" class="md:col-span-3">
                    <x-forms.input name="term" id="term" placeholder="ex.: 1234567890-01" />
                </x-forms.field>
                <x-forms.field label="Procurar em" name="where" class="md:col-span-1">
                    <x-forms.select name="where" id="where">
                        <option value="both">Linhas e payloads</option>
                        <option value="lines">Linhas de log</option>
                        <option value="payloads">Payloads</option>
                    </x-forms.select>
                </x-forms.field>
                <x-forms.field label="Pipeline" name="pipeline" hint="Nome exato. Opcional com termo." class="md:col-span-2">
                    <x-forms.input name="pipeline" id="pipeline" placeholder="ex.: evt-send-estoque-vtex" />
                </x-forms.field>
                <x-forms.field label="Ambiente" name="environment" class="md:col-span-1">
                    <x-forms.select name="environment" id="environment">
                        <option value="prod">Produção</option>
                        <option value="test">Teste</option>
                    </x-forms.select>
                </x-forms.field>
                <x-forms.field label="Desde" name="since" hint="“6h”, “2d” ou “03/11 14:00”" class="md:col-span-2">
                    <x-forms.input name="since" id="since" value="1d" />
                </x-forms.field>
                <x-forms.field label="Até" name="until" hint="Vazio = agora" class="md:col-span-2">
                    <x-forms.input name="until" id="until" />
                </x-forms.field>
                <div class="flex items-end gap-2 md:col-span-1">
                    <x-forms.button data-ak-ajax="digibee-logs-form" data-ak-action="{{ route('digibee-logs.search') }}" class="w-full">
                        Buscar
                    </x-forms.button>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-line pt-3">
                <x-forms.button variant="ghost" data-ak-ajax="digibee-logs-form" data-ak-action="{{ route('digibee-logs.reports.store') }}"
                    class="!px-2.5 !py-1.5 text-xs">
                    Gerar relatório com todas
                </x-forms.button>
                <p class="text-xs text-muted">
                    Abre o log de todas as execuções encontradas (até {{ \App\Jobs\CollectDigibeeLogReport::MAX_OPEN }}) e resume erros, tempos e chamadas HTTP. Leva alguns minutos.
                    Logs ficam 10 dias em produção e 3 em teste.
                </p>
            </div>
        </form>

        <x-digibee.log-results />

        <div class="rounded-card border border-line bg-surface shadow-card">
            <h2 class="border-b border-line px-5 py-3 font-display text-base font-semibold text-ink">Relatórios recentes</h2>
            @forelse ($reports as $report)
                <a href="{{ route('digibee-logs.reports.show', $report) }}"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-5 py-2.5 text-sm last:border-b-0 hover:bg-raised">
                    <x-ui.status-badge :status="$report->status" />
                    <span class="font-medium text-ink">{{ $report->pipeline ?? 'todas as pipelines' }}</span>
                    @if ($report->term)
                        <span class="truncate text-muted">“{{ \Illuminate\Support\Str::limit($report->term, 40) }}”</span>
                    @endif
                    <span class="text-xs text-muted">{{ $report->environment }} · {{ $report->from_at->setTimezone(config('app.display_timezone'))->format('d/m H:i') }}–{{ $report->to_at->setTimezone(config('app.display_timezone'))->format('d/m H:i') }}</span>
                    <span class="ml-auto text-xs text-muted">{{ $report->askedBy() }}, {{ $report->created_at->diffForHumans() }}</span>
                </a>
            @empty
                <p class="px-5 py-4 text-sm text-muted">Nenhum relatório nos últimos {{ \App\Models\DigibeeLogReport::RETENTION_DAYS }} dias.</p>
            @endforelse
        </div>
    </div>
</x-layouts.layout>
