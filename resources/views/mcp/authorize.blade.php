{{-- The OAuth consent screen — the ONE page a person sees while connecting an
     MCP client, and usually the only page of this app they will ever see inside
     a popup window.

     Self-contained (its own <html>, no `x-layouts.layout`), like the error
     pages and for a sharper version of the same reason: this opens in a
     connector's small browser window, where the app's sidebar would be a rail of
     links nobody can follow and the mobile drawer would be a menu over a
     decision. What belongs here is who is signing in, what is asking, what it
     will reach, and two buttons.

     Passport hands us `$client`, `$user`, `$scopes`, `$request` and `$authToken`
     (see AuthorizationController). `$client->name` is whatever the connector
     called ITSELF at registration — untrusted text from an open endpoint — so it
     is printed escaped, as ordinary Blade does, and never as a link. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Conectar ao Inventário de Soluções</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-body font-sans text-[14.5px] antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-lg flex-col justify-center px-6 py-10">

        <div class="rounded-card border border-line bg-surface p-6 shadow-card">
            <div class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-field bg-sidebar font-display text-base font-bold text-white">L</span>
                <div>
                    <p class="font-mono text-[10px] font-bold uppercase tracking-[0.14em] text-accent">Inventário de Soluções</p>
                    <h1 class="font-display text-lg font-semibold leading-tight text-ink">Autorizar conexão</h1>
                </div>
            </div>

            <p class="mt-5 text-sm leading-relaxed text-body">
                <strong class="font-semibold text-ink">{{ $client->name }}</strong> quer ler o
                Inventário de Soluções em nome de
                <strong class="font-semibold text-ink">{{ $user->name }}</strong>
                ({{ $user->email }}).
            </p>

            {{-- What the connection reaches, said as the person's own access
                 rather than as the scope's name. A `Reader` — the tier o SSO
                 provisiona — lê a base publicada e nada do catálogo, que é
                 exatamente o que vê no navegador (App\Mcp\Actor). --}}
            <div class="mt-4 rounded-field border border-line bg-raised/60 px-4 py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">O que essa conexão vai ler</p>
                <ul class="mt-2 space-y-1.5 text-[13px] leading-relaxed text-body">
                    @if ($user->role->canReadInventory())
                        <li>· O catálogo: soluções, diagramas, pessoas (com contatos) e empresas.</li>
                    @endif
                    <li>· A documentação dos cadernos publicados na base de conhecimento.</li>
                    <li>· Valores protegidos nunca saem — o modelo vê só que existe um.</li>
                    <li>· <strong class="font-semibold text-ink">Somente leitura.</strong> Nada é criado, alterado ou apagado.</li>
                </ul>
                @unless ($user->role->canReadInventory())
                    <p class="mt-2.5 text-xs leading-relaxed text-muted">
                        Sua conta lê a base de conhecimento. O catálogo de soluções não
                        estará disponível nesta conexão, como não está no app.
                    </p>
                @endunless
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-forms.button>Autorizar</x-forms.button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-forms.button variant="ghost">Cancelar</x-forms.button>
                </form>
            </div>
        </div>

        <p class="mt-4 px-1 text-xs leading-relaxed text-muted">
            Só autorize se você mesmo acabou de adicionar o Inventário como conector.
            Você pode desfazer isso depois em
            <a href="{{ route('mcp.connect') }}" class="text-accent hover:underline">Conexão MCP</a>.
        </p>
    </div>
</body>
</html>
