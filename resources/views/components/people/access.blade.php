{{-- "Acesso" — whether this person can log in, as what, and the link that lets
     them set their own password. Sits beside "Sistemas" e "Anotações" because
     it is the same kind of fact: something true about this person.

     Three states, and the ordinary one is the FIRST: most people in the catalog
     are vendor contacts who will never have an account, so "sem acesso" is said
     plainly instead of the card hiding itself. --}}
<div id="{{ $domId }}" class="rounded-card border border-line bg-surface shadow-card">
    <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
        <div class="flex items-center gap-2">
            <x-heroicon-o-key class="size-4 text-muted" />
            <h2 class="font-display text-base font-semibold text-ink">Acesso</h2>
        </div>

        @if ($account)
            <span @class([
                'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                'bg-accent-soft text-accent' => $account->role->isAdmin(),
                'bg-lime-soft text-lime-ink' => $account->role === \App\Enums\UserRole::Writer,
                'bg-raised text-muted' => ! $account->role->canWrite(),
            ])>{{ $account->role->label() }}</span>
        @endif
    </div>

    <div class="px-5 py-4">
        @if (! $account)
            {{-- No account. For an admin this is where one is granted; for
                 everyone else it is simply the answer to "essa pessoa entra no
                 sistema?" --}}
            <p class="text-sm text-muted">
                {{ $person->name }} não tem acesso ao inventário — está no catálogo como registro.
            </p>

            @if ($canManage)
                @if (blank($person->email))
                    {{-- The e-mail IS the login, so there is nothing to grant
                         yet. Saying which field is missing beats a disabled
                         button with no explanation. --}}
                    <p class="mt-3 rounded-field border border-dashed border-line bg-raised px-3 py-2 text-xs text-muted">
                        Cadastre o <strong class="font-semibold text-ink">e-mail</strong> desta pessoa para poder
                        conceder acesso — é com ele que ela faz login.
                    </p>
                @else
                    <form id="person-access-form" class="mt-3 flex flex-wrap items-end gap-2">
                        @csrf
                        <div class="min-w-40 flex-1">
                            <x-forms.label for="person-access-role" class="!text-[11px]">Perfil</x-forms.label>
                            <x-forms.select id="person-access-role" name="role" class="!py-1.5 text-sm">
                                @foreach ($roleOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected($option['value'] === 'viewer')>{{ $option['label'] }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <x-forms.button data-ak-ajax="person-access-form" data-ak-action="{{ $accessUrl }}"
                            class="!shrink-0 !px-3 !py-1.5 text-xs">
                            Conceder acesso
                        </x-forms.button>
                    </form>

                    <p class="mt-2 text-[11px] text-faint">
                        Cria a conta para <strong class="font-medium">{{ $person->email }}</strong> e gera um link
                        para a pessoa definir a própria senha.
                    </p>
                @endif

                {{-- Accounts nobody claimed. This is the other half of the
                     accounts list: an account can exist without a Person (the
                     admin do seeder é exatamente isso), and saying "these two
                     are the same human" is a link, not a new account. --}}
                @if ($orphanAccounts->isNotEmpty())
                    <form id="person-link-account-form" class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-3">
                        @csrf
                        @method('PATCH')
                        <div class="min-w-48 flex-1">
                            <x-forms.label for="person-link-account" class="!text-[11px]">
                                Ou vincular uma conta que já existe
                            </x-forms.label>
                            <x-forms.select id="person-link-account" name="user_id" class="!py-1.5 text-sm">
                                @foreach ($orphanAccounts as $orphan)
                                    <option value="{{ $orphan->id }}">{{ $orphan->name }} — {{ $orphan->email }}</option>
                                @endforeach
                            </x-forms.select>
                        </div>
                        <x-forms.button data-ak-ajax="person-link-account-form" data-ak-action="{{ $linkUrl }}"
                            variant="ghost" class="!shrink-0 !px-3 !py-1.5 text-xs">
                            Vincular
                        </x-forms.button>
                    </form>
                @endif
            @endif
        @else
            {{-- Has an account. --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ $account->email }}</p>
                    <p class="mt-0.5 text-xs text-muted">Entra no inventário com este e-mail.</p>
                </div>

                @if ($canChangeRole)
                    <x-ui.inline-edit
                        name="role"
                        type="select"
                        :options="$roleOptions"
                        :value="$account->role->value"
                        :nullable="false"
                        :action="$roleUrl"
                        label="Perfil"
                        edit-class="min-w-44"
                        class="shrink-0">
                        <span class="text-xs font-medium text-accent">Alterar perfil</span>
                    </x-ui.inline-edit>
                @endif
            </div>

            @if ($canManage)
                <div class="mt-4 border-t border-line pt-3">
                    @if ($accessLink)
                        <p class="text-[11px] font-medium text-muted">
                            Link para definir a senha — vale até
                            {{ $expiresAt->translatedFormat('d/M/Y \à\s H:i') }}
                        </p>
                        <div class="mt-1.5 flex items-center gap-2" data-ak-copy>
                            <x-forms.input type="text" readonly data-ak-copy-field
                                value="{{ $accessLink }}" class="!h-9 flex-1 !text-xs"
                                aria-label="Link de acesso" />
                            <x-forms.button type="button" variant="ghost" data-ak-copy-trigger
                                data-ak-copy-message="Link de acesso copiado."
                                class="!h-9 !w-9 shrink-0 !p-0" aria-label="Copiar link">
                                <x-heroicon-o-clipboard-document class="size-5" />
                            </x-forms.button>
                        </div>
                        <p class="mt-1.5 text-[11px] text-faint">
                            O link abre a tela de definir senha — não entra na conta. Ele deixa de funcionar
                            assim que a pessoa define a senha.
                        </p>
                        {{-- Hidden sibling forms, one per verb: `ajax-post.js`
                             always POSTs and reads its body from the form the
                             button names, so the method has to be spoofed with
                             `@method` and a bodyless button would throw on
                             `new FormData(null)`. Same shape as
                             x-attribute-options.group-list's trash button. --}}
                        <form id="person-access-refresh-form" class="hidden">@csrf</form>
                        <form id="person-access-link-kill-form" class="hidden">@csrf @method('DELETE')</form>

                        <div class="mt-2 flex items-center gap-3">
                            <button type="button" data-ak-ajax="person-access-refresh-form" data-ak-action="{{ $refreshUrl }}"
                                class="text-[11px] font-medium text-accent hover:underline">
                                Gerar novo link
                            </button>
                            <button type="button" data-ak-ajax="person-access-link-kill-form" data-ak-action="{{ $killLinkUrl }}"
                                data-ak-confirm="Revogar o link? A pessoa não conseguirá definir a senha por ele."
                                class="text-[11px] font-medium text-crit hover:underline">
                                Revogar link
                            </button>
                        </div>
                    @else
                        <p class="text-xs text-muted">
                            Nenhum link de acesso ativo — a pessoa já definiu a senha, ou o link foi revogado.
                        </p>
                        <form id="person-access-mint-form" class="hidden">@csrf</form>
                        <button type="button" data-ak-ajax="person-access-mint-form" data-ak-action="{{ $refreshUrl }}"
                            class="mt-2 text-[11px] font-medium text-accent hover:underline">
                            Gerar link para definir senha
                        </button>
                    @endif
                </div>

                <div class="mt-4 border-t border-line pt-3">
                    <form id="person-access-revoke-form" class="hidden">@csrf @method('DELETE')</form>
                    <button type="button" data-ak-ajax="person-access-revoke-form" data-ak-action="{{ $revokeUrl }}"
                        data-ak-confirm="Remover o acesso de {{ $person->name }}? A conta deixa de funcionar e a pessoa continua no catálogo."
                        class="text-xs font-medium text-crit hover:underline">
                        Remover acesso
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
