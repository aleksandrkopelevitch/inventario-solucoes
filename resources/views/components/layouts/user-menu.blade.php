<div id="{{ $domId }}" class="relative mt-1.5">
    <x-forms.button type="button" variant="ghost" data-ak-toggle="sidebar-user-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
        class="!flex w-full !justify-start !gap-2.5 border-t border-white/[0.08] !px-2 !py-2.5 text-left hover:!bg-white/[0.05]">
        <x-ui.avatar :name="auth()->user()->name" :src="auth()->user()->avatarUrl()" size="md" />
        <span class="min-w-0">
            <span class="block truncate text-[13px] font-semibold text-white">{{ auth()->user()->name }}</span>
            <span class="block text-[11px] text-sidebar-faint">{{ auth()->user()->role?->label() }}</span>
        </span>
        <x-heroicon-o-chevron-up class="ml-auto size-4 shrink-0 text-sidebar-faint" />
    </x-forms.button>

    <div id="sidebar-user-dropdown" class="hidden absolute inset-x-0 bottom-full z-50 mb-1.5 overflow-hidden rounded-field border border-line bg-surface py-1 shadow-xl">
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
    </div>
</div>
