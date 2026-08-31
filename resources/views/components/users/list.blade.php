<div id="users-list-slot" class="space-y-4">
    <div class="space-y-1.5">
        @forelse ($users as $user)
            <div class="flex items-center justify-between gap-3 rounded-field border border-line px-3 py-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ $user->name }}</p>
                    <p class="truncate text-xs text-muted">{{ $user->email }}</p>
                </div>
                {{-- The badge IS the control (x-ui.inline-edit, the pattern the
                     solution header's attribute badges use): it reads as text
                     and opens a select on the pencil or a double click. A row
                     that may not be changed — your own, and the last remaining
                     admin — renders the same badge with no affordance, because
                     `editable` false is exactly what the component draws then.
                     `$changeable` mirrors the two refusals in
                     UpdateUserRoleRequest::after(); the request stays the
                     authority. --}}
                <x-ui.inline-edit
                    name="role"
                    type="select"
                    :options="$roleOptions"
                    :value="$user->role->value"
                    :nullable="false"
                    :action="route('users.update', $user)"
                    :editable="$changeable[$user->id] ?? false"
                    label="Perfil"
                    edit-class="min-w-44"
                    class="shrink-0">
                    <span @class([
                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        'bg-accent-soft text-accent' => $user->role->isAdmin(),
                        'bg-lime-soft text-lime-ink' => $user->role === \App\Enums\UserRole::Writer,
                        'bg-raised text-muted' => ! $user->role->canWrite(),
                    ])>
                        {{ $user->role->label() }}
                    </span>
                </x-ui.inline-edit>
            </div>
        @empty
            <p class="text-sm text-muted">Nenhum usuário cadastrado ainda.</p>
        @endforelse
    </div>

    <form id="user-invite-form" class="space-y-2 border-t border-line pt-3">
        @csrf
        <p class="text-xs font-medium text-ink">Convidar novo usuário</p>
        <div class="flex items-center gap-1.5">
            <x-forms.input name="name" placeholder="Nome" class="flex-1 !py-1.5 text-sm" />
            <x-forms.input name="email" type="email" placeholder="E-mail" class="flex-1 !py-1.5 text-sm" />
        </div>
        <div class="flex items-center gap-1.5">
            <x-forms.select name="role" class="flex-1 !py-1.5 text-sm">
                @foreach (\App\Enums\UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected($role === \App\Enums\UserRole::Viewer)>{{ $role->label() }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.button data-ak-ajax="user-invite-form" data-ak-action="{{ route('users.store') }}" class="!shrink-0 !px-2.5 !py-1.5 text-xs">
                Convidar
            </x-forms.button>
        </div>
        <p class="text-[11px] text-faint">
            A pessoa convidada recebe um e-mail para definir a própria senha antes de acessar.
        </p>
    </form>
</div>
