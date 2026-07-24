<div id="{{ $domId }}" data-ak-integration-list
    data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-statuses="{{ json_encode($statusesList) }}"
    class="flex flex-col gap-3" aria-label="Integrações da solução">
    @if ($rows->count())
        {{-- Rail count — lives inside the slot, so create/delete keep it in
             sync automatically (renaming/status changes never affect it). --}}
        <p class="px-0.5 text-xs font-medium text-muted">
            {{ $rows->count() }} {{ $rows->count() === 1 ? 'integração' : 'integrações' }}
        </p>
    @endif
    @can('create', App\Models\Integration::class)
        {{-- Creates a new Integration (name only) with the current solution as
             the root node — the data-viz on the right takes over from there
             (blocks, links, protocol, rename/status). --}}
        <form id="integration-create-form" class="flex items-center gap-2">
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

    <div class="flex flex-col gap-2">
        @forelse ($rows as $row)
            @php($integration = $row['integration'])
            {{-- `data-status` (raw enum value) drives the status dot + pill
                 colours via `group-data-[status=…]:` utilities below — a
                 single source of truth. `integration-viz.js::patchRowMeta`
                 swaps just this attribute after a status change, so both
                 recolour without any tone map duplicated in JS. --}}
            <div data-ak-integration-select="{{ $integration->slug }}"
                data-integration-name="{{ $integration->name }}"
                {{-- Hyphenated (not the raw `in_development`): Tailwind turns
                     an underscore inside an arbitrary variant value into a
                     space, so `group-data-[status=in-development]` only fires
                     with a hyphen. Styling token only — JS logic still uses
                     the raw enum value from the graph JSON. --}}
                data-status="{{ str_replace('_', '-', $integration->status->value) }}"
                @if ($row['graph']) data-integration-graph="{{ json_encode($row['graph']) }}" @endif
                role="button" tabindex="0" aria-pressed="false"
                class="group flex cursor-pointer items-center gap-2.5 rounded-field border border-line bg-surface px-3.5 py-2.5 transition-colors hover:border-accent-line hover:bg-accent-soft/40 aria-pressed:border-lime aria-pressed:bg-surface aria-pressed:ring-2 aria-pressed:ring-lime aria-pressed:hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                {{-- Status dot — quick visual anchor, tinted by status. --}}
                <span title="{{ $integration->status->label() }}"
                    class="size-2 shrink-0 rounded-full group-data-[status=active]:bg-cat-emerald group-data-[status=in-development]:bg-hot group-data-[status=planned]:bg-cat-blue group-data-[status=deprecated]:bg-faint"></span>
                <span class="min-w-0 flex-1">
                    <span data-ak-integration-name class="block truncate text-sm font-medium text-ink">{{ $integration->name }}</span>
                    @if ($row['summary'])
                        <span data-ak-integration-summary class="block truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                    @endif
                </span>
                <span data-ak-integration-status
                    class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 group-data-[status=active]:bg-cat-emerald-soft group-data-[status=active]:text-cat-emerald-ink group-data-[status=active]:ring-cat-emerald-line group-data-[status=in-development]:bg-hot-soft group-data-[status=in-development]:text-hot group-data-[status=in-development]:ring-hot-line group-data-[status=planned]:bg-cat-blue-soft group-data-[status=planned]:text-cat-blue-ink group-data-[status=planned]:ring-cat-blue-line group-data-[status=deprecated]:bg-raised group-data-[status=deprecated]:text-muted group-data-[status=deprecated]:ring-line">{{ $integration->status->label() }}</span>
                {{-- Row actions — dimmed at rest, lit on hover / when selected /
                     when a child has keyboard focus, so the row reads calmer
                     but the actions stay tappable on touch (opacity ≠ hidden). --}}
                <span class="flex shrink-0 items-center gap-0.5 opacity-45 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 group-aria-[pressed=true]:opacity-100">
                    <a href="{{ route('solutions.integrations.docs.edit', [$solution, $integration]) }}"
                        title="Documentação da integração"
                        class="inline-flex shrink-0 items-center rounded-field p-1.5 text-muted transition-colors hover:bg-accent-soft hover:text-accent"
                        onclick="event.stopPropagation()">
                        <x-heroicon-o-document-text class="size-4" />
                    </a>
                    @can('delete', $integration)
                        <x-forms.button type="button" variant="ghost"
                            data-ak-ajax="integration-delete-{{ $integration->id }}"
                            data-ak-action="{{ route('solutions.integrations.destroy', [$solution, $integration]) }}"
                            data-ak-confirm="Excluir a integração &quot;{{ $integration->name }}&quot;? Esta ação não pode ser desfeita."
                            title="Excluir integração"
                            class="!shrink-0 !p-1.5 hover:!text-crit">
                            <x-heroicon-o-trash class="size-4" />
                        </x-forms.button>
                    @endcan
                </span>
            </div>
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
