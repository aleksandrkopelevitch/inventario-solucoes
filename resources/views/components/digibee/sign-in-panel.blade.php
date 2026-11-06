{{-- A sessão Digibee que o servidor guarda e o login que a renova. Um slot só,
     trocado por cada ação e pelo polling de status quando o login muda de
     estado (App\View\Components\Digibee\SignInPanel). --}}
@php
    use App\Enums\RemoteSignInStatus;

    $status = $signIn?->status;
    $statusUrl = $signIn ? route('digibee-session.sign-in.status', ['signIn' => $signIn->id, 'seen' => $status->value]) : null;
@endphp

<div id="{{ $domId }}" class="space-y-6">

    <div class="rounded-card border border-line bg-surface p-5 shadow-card">
        <h2 class="font-display text-base font-semibold text-ink">Sessão atual</h2>

        @if ($capture === null)
            <p class="mt-1 text-sm text-muted">Nenhuma sessão capturada ainda. Entre na Digibee abaixo para criar a primeira.</p>
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
                Entrou com a conta Digibee de <strong class="font-semibold text-ink">{{ $capture->user?->name ?? 'uma conta removida' }}</strong>,
                {{ $capture->created_at->diffForHumans() }}. Tudo o que o servidor ler da Digibee aparece lá em nome dessa pessoa.
            </p>
        @endif
    </div>

    <div class="rounded-card border border-line bg-surface p-5 shadow-card">
        <h2 class="font-display text-base font-semibold text-ink">Entrar na Digibee</h2>

        @if ($status?->active())

            @if ($status === RemoteSignInStatus::Starting)
                <p class="mt-1 flex items-center gap-2 text-sm text-muted">
                    <x-heroicon-o-arrow-path class="size-4 animate-spin" />
                    Abrindo o navegador no servidor…
                </p>
            @else
                <p class="mt-1 text-xs leading-relaxed text-muted">
                    Este é o navegador do servidor. Clique na tela e use o teclado normalmente: escolha
                    <strong class="font-semibold text-ink">“Leo Madeiras EntraID SSO Login”</strong>, entre com a sua conta e
                    confirme o MFA. Quando a Digibee abrir, a sessão é guardada e o navegador fecha sozinho.
                    @if ($starter && $starter->id !== auth()->id())
                        Quem abriu este login foi {{ $starter->name }}.
                    @endif
                </p>

                <div data-ak-remote-browser="{{ json_encode([
                        'frameUrl' => route('digibee-session.sign-in.frame', $signIn->id),
                        'inputUrl' => route('digibee-session.sign-in.input', $signIn->id),
                        'width'    => 1024,
                        'height'   => 720,
                    ]) }}"
                    tabindex="0"
                    aria-label="Navegador do servidor — clique para focar e digite"
                    class="mt-3 overflow-hidden rounded-field border border-line bg-raised outline-none focus-visible:ring-2 focus-visible:ring-accent/40">
                    <img data-ak-remote-browser-frame alt="Tela do navegador do servidor"
                        class="block aspect-[1024/720] w-full cursor-pointer select-none" draggable="false">
                </div>
                <p class="mt-1.5 text-[11px] text-muted">
                    Colar (Ctrl+V) funciona. O que você digita vai direto para a página: o iSol não guarda nem registra nenhuma tecla.
                </p>
            @endif

            <form id="digibee-sign-in-cancel" class="mt-3">
                @csrf
                @method('DELETE')
                <x-forms.button variant="ghost" data-ak-ajax="digibee-sign-in-cancel"
                    data-ak-action="{{ route('digibee-session.sign-in.destroy', $signIn->id) }}"
                    class="!px-2.5 !py-1.5 text-xs">
                    Cancelar login
                </x-forms.button>
            </form>

            <div data-ak-poll="{{ $statusUrl }}" data-ak-poll-interval="2000" data-ak-poll-max="450"
                data-ak-poll-give-up="O login no servidor não respondeu — atualize a página para conferir."></div>

        @else

            @if ($status === RemoteSignInStatus::Captured)
                <p class="mt-1 text-sm font-medium text-cat-emerald-ink">Pronto: a sessão foi capturada e o navegador do servidor fechou.</p>
            @elseif ($status === RemoteSignInStatus::Failed)
                <p class="mt-1 text-sm font-medium text-hot">{{ $signIn->message ?? 'O login não terminou.' }}</p>
            @elseif ($status === RemoteSignInStatus::Cancelled)
                <p class="mt-1 text-sm text-muted">O último login foi cancelado.</p>
            @endif

            <p class="mt-1 text-xs leading-relaxed text-muted">
                Abre um navegador no servidor, no link de SSO da Digibee, e mostra ele aqui para você entrar com a sua conta.
                A Digibee dá 24 horas a cada sessão; depois disso é preciso entrar de novo.
            </p>

            <form id="digibee-sign-in-start" class="mt-3">
                @csrf
                <x-forms.button data-ak-ajax="digibee-sign-in-start" data-ak-action="{{ route('digibee-session.sign-in.store') }}"
                    class="!px-3 !py-1.5 text-xs">
                    Entrar na Digibee
                </x-forms.button>
            </form>

        @endif
    </div>
</div>
