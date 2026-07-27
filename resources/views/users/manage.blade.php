@php
    $users = \App\Models\User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']);
@endphp

<div class="flex items-start justify-between border-b border-line px-5 py-4">
    <div>
        <h2 class="font-display text-lg font-semibold text-ink">Usuários</h2>
        <p class="mt-0.5 text-xs text-muted">Convide novas pessoas para acessar o inventário.</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent">&times;</x-forms.button>
</div>

<div class="max-h-[70vh] overflow-y-auto px-5 py-4">
    <x-users.list :users="$users" />
</div>
