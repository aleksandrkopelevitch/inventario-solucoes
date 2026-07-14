<x-layouts.auth title="Recuperar senha">

    <h1 class="mb-2 font-display text-2xl font-semibold text-ink">Recuperar senha</h1>
    <p class="mb-6 text-sm text-muted">Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>

    <form id="forgot-form" novalidate class="space-y-4">
        @csrf

        <x-forms.field label="E-mail" for="email" name="email">
            <x-forms.input id="email" name="email" type="email" autocomplete="email" placeholder="seu@email.com" required />
        </x-forms.field>

        <x-forms.button data-ak-ajax="forgot-form" data-ak-action="{{ route('password.email') }}" class="mt-2 w-full">
            Enviar link
        </x-forms.button>

        <p class="mt-5 text-center text-sm text-muted">
            <a href="{{ route('login.create') }}" class="font-medium text-accent hover:underline">Voltar ao login</a>
        </p>
    </form>

</x-layouts.auth>
