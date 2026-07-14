<div class="flex items-center justify-between border-b border-line px-5 py-4">
    <h2 class="font-display text-lg font-semibold text-ink">Editar perfil</h2>
    <button type="button" data-close class="rounded-field p-1 text-xl leading-none text-faint hover:text-ink">&times;</button>
</div>

<form id="profile-edit-form" class="mx-auto flex w-full max-w-sm flex-col gap-4 px-5 py-4">
    @csrf
    @method('PATCH')

    <x-forms.field label="Foto" name="avatar">
        <x-forms.image-upload name="avatar" :value="$user->avatarUrl()" size="h-20 w-20" />
    </x-forms.field>

    <x-forms.field label="Nome" for="profile-name" name="name" required>
        <x-forms.input id="profile-name" name="name" :value="old('name', $user->name)" required />
    </x-forms.field>

    <x-forms.field label="E-mail" for="profile-email" name="email" required>
        <x-forms.input id="profile-email" type="email" name="email" :value="old('email', $user->email)" required />
    </x-forms.field>

    <x-forms.button data-ak-ajax="profile-edit-form" data-ak-action="{{ route('profile.update') }}" class="mt-1 w-full">
        Salvar
    </x-forms.button>
</form>
