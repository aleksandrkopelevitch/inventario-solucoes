{{-- O log inteiro de uma execução, no painel lateral. Já redigido pelo
     controller (LogRedactor). --}}
<div class="flex h-full min-h-0 flex-col">
    <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Execução · {{ $environment }}</p>
            <h2 class="mt-0.5 truncate font-display text-base font-semibold text-ink">{{ $execution['pipeline'] ?? 'Execução' }}</h2>
            <p class="mt-0.5 break-all font-mono text-[11px] text-muted">{{ $key }}</p>
        </div>
        <x-forms.button type="button" variant="ghost" data-ak-panel-close class="!px-2 !py-1 text-xs">Fechar</x-forms.button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
        @if ($execution === null)
            <p class="text-sm text-muted">Nenhuma linha de log para esta execução — a chave está errada ou o log já expirou (10 dias em produção, 3 em teste).</p>
        @else
            <ol class="space-y-1.5">
                @foreach ($execution['lines'] as $line)
                    <li class="font-mono text-[11px] leading-relaxed">
                        <span class="text-muted">{{ substr((string) ($line['at'] ?? ''), 11, 12) }}</span>
                        <span @class([
                            'font-semibold',
                            'text-hot' => in_array(strtoupper((string) $line['level']), ['ERROR', 'FATAL']),
                            'text-cat-amber-ink' => in_array(strtoupper((string) $line['level']), ['WARN', 'WARNING']),
                            'text-muted' => ! in_array(strtoupper((string) $line['level']), ['ERROR', 'FATAL', 'WARN', 'WARNING']),
                        ])>{{ $line['level'] }}</span>
                        <span class="whitespace-pre-wrap break-all text-ink">{{ $line['message'] }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
