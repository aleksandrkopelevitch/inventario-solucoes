<div id="{{ $domId }}" class="mt-5 rounded-card border border-line bg-surface p-6 shadow-card" aria-label="Integrações da solução">
    <div class="flex items-baseline gap-2.5">
        <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Integrações</span>
        <h2 class="font-display text-[22px] font-semibold text-ink">Integrações</h2>
    </div>

    @if ($rows->count())
        {{-- Lives inside the slot, so create/delete keep it in sync
             automatically (renaming/status changes never affect it). --}}
        <p class="mt-1 text-sm text-muted">
            {{ $rows->count() }} {{ $rows->count() === 1 ? 'integração' : 'integrações' }} — cada uma reúne texto e diagrama no mesmo lugar.
        </p>
    @endif
    @can('create', App\Models\Integration::class)
        {{-- Creates a new Integration (name only) with the current solution as
             the root node — its own page (Documentação/Diagrama tabs) takes
             over from there (blocks, links, protocol, rename/status). --}}
        <form id="integration-create-form" class="mt-4 flex items-center gap-2">
            @csrf
            <x-forms.input type="text" name="name" placeholder="Nome da nova integração (opcional)"
                class="!h-9 flex-1 !text-sm" />
            <x-forms.button type="button"
                data-ak-ajax="integration-create-form"
                data-ak-action="{{ route('solutions.integrations.store', $solution) }}"
                class="!h-9 !shrink-0 !px-3 !text-xs">
                <x-heroicon-o-plus class="size-4" /> Nova
            </x-forms.button>
        </form>
    @endcan

    <div class="mt-4 flex flex-col gap-2">
        @forelse ($rows as $row)
            @php($integration = $row['integration'])
            {{-- `data-status` (raw enum value) drives the status dot + pill
                 colours via `group-data-[status=…]:` utilities below — a
                 single source of truth. Hyphenated (not the raw
                 `in_development`): Tailwind turns an underscore inside an
                 arbitrary variant value into a space, so
                 `group-data-[status=in-development]` only fires with a hyphen. --}}
            <a href="{{ $row['editUrl'] }}"
                data-status="{{ str_replace('_', '-', $integration->status->value) }}"
                class="group flex items-center gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                {{-- Status dot — quick visual anchor, tinted by status. --}}
                <span title="{{ $integration->status->label() }}"
                    class="size-2 shrink-0 rounded-full group-data-[status=active]:bg-cat-emerald group-data-[status=in-development]:bg-hot group-data-[status=planned]:bg-cat-blue group-data-[status=deprecated]:bg-faint"></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ $integration->name }}</span>
                    @if ($row['summary'])
                        <span class="block truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                    @endif
                </span>
                <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 group-data-[status=active]:bg-cat-emerald-soft group-data-[status=active]:text-cat-emerald-ink group-data-[status=active]:ring-cat-emerald-line group-data-[status=in-development]:bg-hot-soft group-data-[status=in-development]:text-hot group-data-[status=in-development]:ring-hot-line group-data-[status=planned]:bg-cat-blue-soft group-data-[status=planned]:text-cat-blue-ink group-data-[status=planned]:ring-cat-blue-line group-data-[status=deprecated]:bg-raised group-data-[status=deprecated]:text-muted group-data-[status=deprecated]:ring-line">{{ $integration->status->label() }}</span>
                {{-- Reassures that opening the row gets both, not just one. --}}
                <span title="Documentação + diagrama"
                    class="inline-flex shrink-0 items-center gap-1 rounded-full border border-dashed border-line-2 px-2 py-0.5 text-xs font-medium text-muted">
                    <x-heroicon-o-document-text class="size-3.5" />+<x-heroicon-o-share class="size-3.5" />
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
                <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint" />
            </a>
            @can('delete', $integration)
                <form id="integration-delete-{{ $integration->id }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @empty
            <p class="rounded-field border border-dashed border-line px-3 py-6 text-center text-sm text-muted">
                Nenhuma integração cadastrada.
            </p>
        @endforelse
    </div>
</div>
