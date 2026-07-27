<x-layouts.auth title="Entrar">

    <h1 class="mb-6 font-display text-2xl font-semibold text-ink">Entrar</h1>

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
            Não tem uma conta? Peça a um administrador para te convidar.
        </p>
    </form>

</x-layouts.auth>
