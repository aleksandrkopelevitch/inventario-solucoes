<x-layouts.auth title="Redefinir senha">

    <h1 class="mb-6 font-display text-2xl font-semibold text-ink">Redefinir senha</h1>

    <form id="reset-form" novalidate class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <x-forms.field label="E-mail" for="email" name="email">
            <x-forms.input id="email" name="email" type="email" autocomplete="email" :value="$email" placeholder="seu@email.com" required />
        </x-forms.field>

        <x-forms.field label="Nova senha" for="password" name="password">
            <x-forms.input id="password" name="password" type="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required />
        </x-forms.field>

        <x-forms.field label="Confirmar nova senha" for="password_confirmation" name="password_confirmation">
            <x-forms.input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repita a senha" required />
        </x-forms.field>

        <x-forms.button data-ak-ajax="reset-form" data-ak-action="{{ route('password.update') }}" class="mt-2 w-full">
            Redefinir senha
        </x-forms.button>
    </form>

</x-layouts.auth>
