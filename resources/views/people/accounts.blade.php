<x-layouts.layout title="Contas de acesso">
    <a href="{{ route('people.index') }}"
       class="group mb-4 inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-ink">
        <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Pessoas
    </a>

    <x-ui.hero-panel compact class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
                    <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
                    Diretório
                </span>
                <h1 class="mt-2 font-display text-[34px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">
                    Quem tem acesso
                </h1>
                {{-- The sentence a reader needs first: this screen READS, and the
                     lever is on each person's page. Before the move it was the
                     other way round — a modal that granted access to an e-mail,
                     with no way to say whose e-mail it was. --}}
                <p class="mt-1 text-sm text-[color:var(--color-glow-ink)]/70">
                    Contas que entram no inventário. Conceder, trocar o perfil ou remover
                    acesso é feito na página da pessoa.
                </p>
            </div>
        </div>
    </x-ui.hero-panel>

    {{-- Full width, like the hero above it: a 3xl cap left both cards
         floating in the left two-thirds of a screen whose header ran edge
         to edge, which reads as an unfinished layout rather than as a
         reading measure. --}}
    <div class="space-y-6">
        <x-people.accounts />

        {{-- The invite form: the other door to the same destination. It sends an
             e-mail where a person's page hands over a link — and since
             2026-09-01 it also CREATES the catalog row (or reuses the one
             already filed under that e-mail), so an account is never born
             without a human. Its old title said "sem cadastro de pessoa",
             which is exactly what it no longer does. --}}
        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h2 class="font-display text-base font-semibold text-ink">Convidar por e-mail</h2>
            <p class="mt-0.5 text-xs text-muted">
                Cria a conta, cadastra a pessoa no catálogo e envia o convite por e-mail. Se ela já
                estiver no catálogo com esse e-mail, a conta é vinculada ao registro que já existe.
            </p>

            <form id="user-invite-form" class="mt-3 space-y-2">
                @csrf
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-forms.input name="name" placeholder="Nome" class="min-w-40 flex-1 !py-1.5 text-sm" />
                    <x-forms.input name="email" type="email" placeholder="E-mail" class="min-w-48 flex-1 !py-1.5 text-sm" />
                </div>
                <div class="flex items-center gap-1.5">
                    <x-forms.select name="role" class="flex-1 !py-1.5 text-sm">
                        @foreach (\App\Enums\UserRole::cases() as $role)
                            <option value="{{ $role->value }}" @selected($role === \App\Enums\UserRole::Viewer)>{{ $role->label() }}</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.button data-ak-ajax="user-invite-form" data-ak-action="{{ route('users.store') }}"
                        class="!shrink-0 !px-2.5 !py-1.5 text-xs">
                        Convidar
                    </x-forms.button>
                </div>
                <p class="text-[11px] text-faint">
                    A pessoa convidada recebe um e-mail para definir a própria senha antes de acessar.
                    Para quem já está no catálogo, conceder o acesso na página da pessoa entrega o
                    mesmo convite como link.
                </p>
            </form>
        </div>
    </div>
</x-layouts.layout>
