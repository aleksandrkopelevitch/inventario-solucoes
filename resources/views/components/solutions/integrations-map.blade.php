{{-- Left column of the solution's "integrações + documentação" card (the frame
     itself is in solutions/show.blade.php — this is the updatable slot, so it
     can't own the card's border). A plain nav list: each row opens that
     integration's own unified page (Documentação/Diagrama tabs). --}}
<div id="{{ $domId }}" class="flex min-w-0 flex-col p-6" aria-label="Integrações da solução">
    <div class="flex items-center gap-2.5">
        <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent text-white">
            <x-heroicon-o-share class="size-4" />
        </span>
        <h2 class="font-display text-[18px] font-semibold text-ink">Integrações</h2>
        @if ($rows->count())
            {{-- Lives inside the slot, so create/delete keep it in sync
                 automatically (renaming/status changes never affect it). --}}
            <span class="rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-muted">{{ $rows->count() }}</span>
        @endif
    </div>

    <p class="mt-1.5 text-sm text-muted">Cada integração reúne texto e diagrama no mesmo lugar.</p>

    @can('create', App\Models\Integration::class)
        {{-- Creates a new Integration (name only) with the current solution as
             the root node and goes STRAIGHT to its page, where the blocks,
             links, protocol and status are authored. Name is optional (falls
             back to the solution's own name), so "Nova" alone is a valid
             gesture. --}}
        <form id="integration-create-form" class="mt-4 flex items-center gap-2">
            @csrf
            <x-forms.input type="text" name="name" placeholder="Nome da nova integração (opcional)"
                class="!h-9 min-w-0 flex-1 !text-sm" />
            <x-forms.button data-ak-ajax="integration-create-form"
                data-ak-action="{{ route('solutions.integrations.store', $solution) }}"
                class="!h-9 !shrink-0 !px-3 !text-xs">
                <x-heroicon-o-plus class="size-4" /> Nova
            </x-forms.button>
        </form>
    @endcan

    <div class="mt-4 flex flex-col gap-2">
        @forelse ($rows as $row)
            @php($integration = $row['integration'])
            <a href="{{ $row['editUrl'] }}"
                class="group flex items-start gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                {{-- Status dot — quick visual anchor, tinted by status. Same
                     tone map as the pill below it (`App\Enums\IntegrationStatus`). --}}
                <span title="{{ $integration->status->label() }}"
                    class="mt-1.5 size-2 shrink-0 rounded-full {{ $integration->status->dotClass() }}"></span>

                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ $integration->name }}</span>
                    {{-- Status and chain summary share the second line: at half
                         the card's width there's no room for a status column of
                         its own beside the name. --}}
                    <span class="mt-1 flex min-w-0 items-center gap-2">
                        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $integration->status->badgeClass() }}">{{ $integration->status->label() }}</span>
                        @if ($row['summary'])
                            <span class="min-w-0 truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                        @endif
                    </span>
                </span>

                @can('delete', $integration)
                    <x-forms.button type="button" variant="ghost"
                        data-ak-ajax="integration-delete-{{ $integration->id }}"
                        data-ak-action="{{ route('solutions.integrations.destroy', [$solution, $integration]) }}"
                        data-ak-confirm="Excluir a integração &quot;{{ $integration->name }}&quot;? Esta ação não pode ser desfeita."
                        title="Excluir integração"
                        class="!shrink-0 opacity-45 !p-1.5 transition-opacity group-hover:opacity-100 hover:!text-crit">
                        <x-heroicon-o-trash class="size-4" />
                    </x-forms.button>
                @endcan
                <x-heroicon-o-chevron-right class="mt-1 size-4 shrink-0 text-faint" />
            </a>
            @can('delete', $integration)
                <form id="integration-delete-{{ $integration->id }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @empty
            <x-ui.empty-state illustration="integrations" illustration-class="max-w-[268px]"
                title="Nenhuma integração cadastrada"
                description="Crie a primeira para desenhar o fluxo entre os sistemas e documentá-lo." />
        @endforelse
    </div>
</div>
