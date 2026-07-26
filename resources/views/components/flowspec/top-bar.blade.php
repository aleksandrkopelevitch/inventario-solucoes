{{-- Slim top bar for the flowSpec chat: the sidebar collapse toggle + a title
     slot. When the conversations rail is collapsed, the toggle shows a
     "Mostrar conversas" label (toggle.js swaps the opened/closed state spans). --}}
<div class="flex items-center gap-2 border-b border-line px-3 py-2">
    <x-forms.button type="button" variant="ghost" data-ak-mobile-nav-open aria-label="Abrir menu"
        class="md:hidden !px-2 !py-1.5">
        <x-heroicon-o-bars-3 class="size-5" />
    </x-forms.button>

    <x-forms.button type="button" variant="ghost" class="!px-2 !py-1.5" title="Mostrar/ocultar conversas"
        data-ak-toggle="fs-sidebar" data-ak-toggle-classes="!w-0 !border-r-0">
        <span id="fs-sidebar-opened-state"><x-heroicon-o-chevron-double-left class="size-4" /></span>
        <span id="fs-sidebar-closed-state" class="hidden whitespace-nowrap">
            <x-heroicon-o-chevron-double-right class="inline size-4 align-text-bottom" /> Mostrar conversas
        </span>
    </x-forms.button>

    <div class="min-w-0 flex-1">
        {{ $slot }}
    </div>

    @can('viewAny', App\Models\FlowspecExample::class)
        <a href="#" data-ak-modal-open="main-modal" data-ak-modal-url="{{ route('flowspec.examples.index') }}"
           class="inline-flex shrink-0 items-center gap-1.5 rounded-field px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-raised hover:text-ink"
           title="Gerenciar a base de referência do especialista">
            <x-heroicon-o-book-open class="size-4" /> Referências
        </a>
    @endcan
</div>
