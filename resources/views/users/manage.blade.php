<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div>
        <h2 class="font-display text-lg font-semibold text-ink">Usuários</h2>
        <p class="mt-0.5 text-xs text-muted">Convide novas pessoas para acessar o inventário.</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<div class="max-h-[70vh] overflow-y-auto px-5 py-4">
    {{-- The CLASS component (App\View\Components\Users\UserList), not the bare
         view: it is what supplies the role options and decides which rows may
         be changed, and it is what a role change swaps back in as
         `users-list-slot`. Rendering the view directly with its own query — as
         this panel used to — meant two sources for one list, and the moment the
         list needed anything beyond `$users` the modal path had it and the slot
         path did not. --}}
    <x-users.user-list />
</div>
