@php
    $ssoEnabled = \App\Support\Auth\EntraSso::configured();
@endphp

<x-layouts.auth title="Entrar">

    <h1 class="mb-6 font-display text-2xl font-semibold text-ink">Entrar</h1>

    {{-- The SSO door, ABOVE the password form and not below it.
         For most people arriving here it is the only one that works: an account
         provisioned by Entra never chose a password, so a form asking for one
         is the wrong first thing to read. It is rendered only when the app
         registration actually exists (`EntraSso::configured()`) — a button that
         leads to a Microsoft error page is worse than no button. --}}
    @if ($ssoEnabled)
        <a href="{{ route('entra.redirect') }}"
           class="mb-5 flex w-full items-center justify-center gap-2.5 rounded-field border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink no-underline shadow-sm transition-colors hover:border-line-2 hover:bg-raised">
            {{-- Microsoft's four squares. Inlined and in their own brand
                 colours, which is the one place in this app a foreign palette
                 belongs: it is somebody else's mark, and recolouring it to the
                 Leo green would make it unrecognisable as the thing the person
                 is being asked to trust. --}}
            <svg class="size-[18px] shrink-0" viewBox="0 0 23 23" aria-hidden="true">
                <path fill="#f25022" d="M1 1h10v10H1z" />
                <path fill="#7fba00" d="M12 1h10v10H12z" />
                <path fill="#00a4ef" d="M1 12h10v10H1z" />
                <path fill="#ffb900" d="M12 12h10v10H12z" />
            </svg>
            Entrar com Microsoft
        </a>

        <div class="mb-5 flex items-center gap-3">
            <span class="h-px flex-1 bg-line"></span>
            <span class="text-xs text-faint">ou com e-mail e senha</span>
            <span class="h-px flex-1 bg-line"></span>
        </div>
    @endif

    <form id="login-form" novalidate class="space-y-4">
        @csrf

        <x-forms.field label="E-mail" for="email" name="email">
            <x-forms.input id="email" name="email" type="email" autocomplete="email" placeholder="seu@email.com" required />
        </x-forms.field>

        <x-forms.field label="Senha" for="password" name="password">
            <x-forms.input id="password" name="password" type="password" autocomplete="current-password" placeholder="••••••••" required />
        </x-forms.field>

        <div class="flex items-center justify-between">
            <x-forms.label class="!flex cursor-pointer items-center gap-2">
                <x-forms.checkbox name="remember" />
                <span class="text-sm text-muted">Lembrar de mim</span>
            </x-forms.label>
            <a href="{{ route('password.request') }}" class="text-sm text-muted hover:text-accent hover:underline">Esqueci minha senha</a>
        </div>

        <x-forms.button data-ak-ajax="login-form" data-ak-action="{{ route('login.store') }}" class="mt-2 w-full">
            Entrar
        </x-forms.button>

        <p class="mt-5 text-center text-xs text-faint">
            @if ($ssoEnabled)
                Com a conta Leo Madeiras, use “Entrar com Microsoft”. Sem ela, peça a
                um administrador para te convidar.
            @else
                Não tem uma conta? Peça a um administrador para te convidar.
            @endif
        </p>
    </form>

</x-layouts.auth>
