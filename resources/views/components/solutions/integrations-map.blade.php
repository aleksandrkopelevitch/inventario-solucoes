<div id="{{ $domId }}" data-ak-integration-list
    data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-statuses="{{ json_encode($statusesList) }}"
    class="flex flex-col gap-3" aria-label="Integrações da solução">
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
            <div data-ak-integration-select="{{ $integration->slug }}"
                data-integration-name="{{ $integration->name }}"
                @if ($row['graph']) data-integration-graph="{{ json_encode($row['graph']) }}" @endif
                role="button" tabindex="0" aria-pressed="false"
                class="group flex cursor-pointer items-center justify-between gap-3 rounded-field border border-line bg-surface px-3.5 py-2.5 transition-colors hover:border-accent-line hover:bg-accent-soft/40 aria-pressed:border-lime aria-pressed:bg-surface aria-pressed:ring-2 aria-pressed:ring-lime aria-pressed:hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                <span class="min-w-0">
                    <span data-ak-integration-name class="block truncate text-sm font-medium text-ink">{{ $integration->name }}</span>
                    @if ($row['summary'])
                        <span data-ak-integration-summary class="block truncate font-mono text-xs text-muted">{{ $row['summary'] }}</span>
                    @endif
                </span>
                <span class="flex shrink-0 items-center gap-1.5">
                    <span data-ak-integration-status class="inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-accent-line">{{ $integration->status->label() }}</span>
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
