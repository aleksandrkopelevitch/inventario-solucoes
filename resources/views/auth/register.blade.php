<x-layouts.auth title="Criar conta">

    <h1 class="mb-6 font-display text-2xl font-semibold text-ink">Criar conta</h1>

    <form id="register-form" novalidate class="space-y-4">
        @csrf

        <x-forms.field label="Nome" for="name" name="name">
            <x-forms.input id="name" name="name" type="text" autocomplete="name" placeholder="Seu nome completo" required />
        </x-forms.field>

        <x-forms.field label="E-mail" for="email" name="email">
            <x-forms.input id="email" name="email" type="email" autocomplete="email" placeholder="seu@email.com" required />
        </x-forms.field>

        <x-forms.field label="Confirmar e-mail" for="email_confirmation" name="email_confirmation">
            <x-forms.input id="email_confirmation" name="email_confirmation" type="email" autocomplete="email" placeholder="seu@email.com" required />
        </x-forms.field>

        <x-forms.field label="Senha" for="password" name="password">
            <x-forms.input id="password" name="password" type="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required />
        </x-forms.field>

        <x-forms.button data-ak-ajax="register-form" data-ak-action="{{ route('register.store') }}" class="mt-2 w-full">
            Criar conta
        </x-forms.button>

        <p class="mt-5 text-center text-sm text-muted">
            Já tem uma conta?
            <a href="{{ route('login.create') }}" class="font-medium text-accent hover:underline">Entrar</a>
        </p>
    </form>

</x-layouts.auth>
