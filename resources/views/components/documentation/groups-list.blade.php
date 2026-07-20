<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-5 shadow-card">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-ink">Grupos</h2>
            <p class="mt-0.5 text-sm text-muted">Páginas de documentação avulsas, fora de qualquer solução.</p>
        </div>

        @can('create', \App\Models\DocumentationGroup::class)
            <x-forms.button type="button" variant="ghost" data-ak-toggle="doc-new-group-form" data-ak-toggle-classes="hidden"
                class="!h-9 !w-9 !shrink-0 !p-0" aria-label="Novo grupo" title="Novo grupo">
                <x-heroicon-o-plus class="size-5" />
            </x-forms.button>
        @endcan
    </div>

    @can('create', \App\Models\DocumentationGroup::class)
        <form id="doc-new-group-form" class="hidden mt-3 flex gap-2">
            @csrf
            <x-forms.input name="name" placeholder="Nome do grupo" class="max-w-xs" autofocus />
            <x-forms.button data-ak-ajax="doc-new-group-form" data-ak-action="{{ route('documentation.groups.store') }}" class="!shrink-0">
                Criar
            </x-forms.button>
        </form>
    @endcan

    @if ($groups->isNotEmpty())
        <div class="mt-4 divide-y divide-line rounded-field border border-line">
            @foreach ($groups as $group)
                <a href="{{ $group['url'] }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm no-underline hover:bg-raised">
                    <span class="font-medium text-ink">{{ $group['name'] }}</span>
                    <span class="shrink-0 text-xs text-muted">{{ $group['pageCount'] }} {{ $group['pageCount'] === 1 ? 'página' : 'páginas' }}</span>
                </a>
            @endforeach
        </div>
    @else
        <p class="mt-4 rounded-field border border-dashed border-line px-4 py-6 text-center text-sm text-muted">
            Nenhum grupo criado ainda.
        </p>
    @endif
</div>
