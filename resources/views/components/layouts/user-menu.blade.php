<div id="{{ $domId }}" class="relative mt-1.5">
    <x-forms.button type="button" variant="ghost" data-ak-toggle="sidebar-user-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
        class="group !relative !size-10 !justify-center !rounded-field !p-0 hover:!bg-white/[0.06]">
        <x-ui.avatar :name="auth()->user()->name" :src="auth()->user()->avatarUrl()" size="md" />

        {{-- Same hover flyout as the nav items — projects the user's name to the right. --}}
        <span class="pointer-events-none absolute left-full top-0 z-50 flex h-10 translate-x-1 items-center whitespace-nowrap rounded-r-field bg-sidebar pl-3 pr-4 text-sm font-medium text-white opacity-0 shadow-[8px_0_20px_-6px_rgba(0,0,0,0.35)] transition-[opacity,transform] duration-150 group-hover:translate-x-0 group-hover:opacity-100">
            {{ auth()->user()->name }}
        </span>
    </x-forms.button>

    <div id="sidebar-user-dropdown" class="hidden absolute bottom-full left-0 z-50 mb-1.5 w-max min-w-[210px] overflow-hidden rounded-field border border-line bg-surface py-1 shadow-xl">
        <a href="#" data-ak-modal-open="main-modal" data-ak-modal-url="{{ route('profile.edit') }}"
           data-ak-toggle="sidebar-user-dropdown" data-ak-toggle-classes="hidden"
           class="flex items-center gap-2 px-3 py-2 text-[13px] font-medium text-ink no-underline hover:bg-raised">
            <x-heroicon-o-user-circle class="size-4 text-muted" /> Editar perfil
        </a>
        @can('manage', \App\Models\AttributeOption::class)
            <a href="#" data-ak-modal-open="main-modal" data-ak-modal-url="{{ route('attribute-options.index') }}"
               data-ak-toggle="sidebar-user-dropdown" data-ak-toggle-classes="hidden"
               class="flex items-center gap-2 px-3 py-2 text-[13px] font-medium text-ink no-underline hover:bg-raised">
                <x-heroicon-o-adjustments-horizontal class="size-4 text-muted" /> Gerenciar atributos
            </a>
        @endcan
        {{-- Was a "Usuários" MODAL here, which is the thing this entry replaces.
             Access is an attribute of a person now, managed on their own page,
             and this points at the roster that reads them all. A real page, so
             it can be linked, bookmarked and reached from /people too. --}}
        @can('manage', \App\Models\User::class)
            <a href="{{ route('people.accounts') }}"
               data-ak-toggle="sidebar-user-dropdown" data-ak-toggle-classes="hidden"
               class="flex items-center gap-2 px-3 py-2 text-[13px] font-medium text-ink no-underline hover:bg-raised">
                <x-heroicon-o-key class="size-4 text-muted" /> Quem tem acesso
            </a>
        @endcan
    </div>
</div>
