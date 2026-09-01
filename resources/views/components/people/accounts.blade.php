{{-- "Quem tem acesso": every ACCOUNT, and which person each one belongs to.

     This list survives the move of access management onto each person's page for
     one reason — an account does not need a Person. `admin@leomadeiras.com.br`
     comes from the seeder and never will have a catalog row, so a screen that
     listed only "people who have accounts" would leave the one account that
     cannot be locked out with no screen at all.

     Every row is a link to somewhere something can be DONE (the person's page);
     an orphan says so and points at the fix, since linking is a gesture on the
     person, not here. --}}
<div id="{{ $domId }}" class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
        <div>
            <h2 class="font-display text-base font-semibold text-ink">Contas de acesso</h2>
            <p class="mt-0.5 text-xs text-muted">
                {{ $accounts->count() }}
                {{ \Illuminate\Support\Str::plural('conta', $accounts->count()) }} —
                o acesso de cada pessoa é gerenciado na página dela.
            </p>
        </div>
    </div>

    <div class="divide-y divide-line">
        @forelse ($accounts as $account)
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink">{{ $account->name }}</p>
                    <p class="truncate text-xs text-muted">{{ $account->email }}</p>
                </div>

                <div class="flex shrink-0 items-center gap-2.5">
                    @if ($account->hasLiveAccessToken())
                        {{-- An outstanding link is worth saying out loud: it
                             means somebody was given access and has not set a
                             password yet, which is the state that quietly goes
                             stale. --}}
                        <span class="rounded-full bg-raised px-2 py-0.5 text-[11px] font-medium text-muted"
                              title="Link de acesso ativo até {{ $account->access_token_expires_at->translatedFormat('d/M/Y H:i') }}">
                            link pendente
                        </span>
                    @endif

                    {{-- The badge IS the control, and this list is the only
                         place an ORPHAN account's role can be changed — it has
                         no person page to carry an Acesso card. Withheld on your
                         own row (see the component). --}}
                    <x-ui.inline-edit
                        name="role"
                        type="select"
                        :options="$roleOptions"
                        :value="$account->role->value"
                        :nullable="false"
                        :action="route('users.update', $account)"
                        :editable="$changeable[$account->id] ?? false"
                        label="Perfil"
                        edit-class="min-w-44"
                        class="shrink-0">
                        <span @class([
                            'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                            'bg-accent-soft text-accent' => $account->role->isAdmin(),
                            'bg-lime-soft text-lime-ink' => $account->role === \App\Enums\UserRole::Writer,
                            'bg-raised text-muted' => ! $account->role->canWrite(),
                        ])>{{ $account->role->label() }}</span>
                    </x-ui.inline-edit>

                    @if ($account->person)
                        <a href="{{ route('people.show', $account->person) }}"
                           class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
                            {{ $account->person->name }}
                            <x-heroicon-o-arrow-top-right-on-square class="size-3.5" />
                        </a>
                    @else
                        <span class="text-xs text-faint" title="Vincule esta conta na página da pessoa">
                            sem pessoa vinculada
                        </span>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-muted">Nenhuma conta cadastrada ainda.</p>
        @endforelse
    </div>
</div>
