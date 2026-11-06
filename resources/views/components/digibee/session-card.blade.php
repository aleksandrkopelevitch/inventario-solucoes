{{-- A sessão Digibee que o servidor guarda: até quando vale e de quem é
     (App\View\Components\Digibee\SessionCard). --}}
<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-5 shadow-card">
    <h2 class="font-display text-base font-semibold text-ink">Sessão atual</h2>

    @if ($capture === null)
        <p class="mt-1 text-sm text-muted">Nenhuma sessão guardada ainda. Siga os passos abaixo para enviar a primeira.</p>
    @else
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            @if ($capture->expired())
                <span class="rounded-full bg-hot-soft px-2 py-0.5 text-xs font-medium text-hot ring-1 ring-hot-line">Expirada</span>
                <span class="text-muted">desde {{ $capture->expires_at?->setTimezone(config('app.display_timezone'))->format('d/m H:i') }}</span>
            @else
                <span class="rounded-full bg-cat-emerald-soft px-2 py-0.5 text-xs font-medium text-cat-emerald-ink ring-1 ring-cat-emerald-line">Válida</span>
                <span class="text-muted">até {{ $capture->expires_at->setTimezone(config('app.display_timezone'))->format('d/m H:i') }}</span>
            @endif
        </div>
        <p class="mt-1.5 text-xs text-muted">
            Enviada por <strong class="font-semibold text-ink">{{ $capture->user?->name ?? 'uma conta removida' }}</strong>,
            {{ $capture->created_at->diffForHumans() }}. Tudo o que o servidor ler da Digibee aparece lá em nome dessa pessoa.
        </p>
    @endif
</div>
