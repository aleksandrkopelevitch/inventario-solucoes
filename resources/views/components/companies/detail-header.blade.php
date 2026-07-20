<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-6 shadow-card">
    <div class="flex flex-wrap items-start gap-4">
        <x-ui.logo :name="$company->name" :src="$company->logo_path" size="lg" />
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-[26px] font-semibold leading-tight text-ink">{{ $company->name }}</h1>
            <p class="mt-0.5 text-sm text-muted">{{ $company->kind->label() }}</p>
            @if ($company->website)
                <a href="{{ $company->website }}" target="_blank" rel="noopener" class="mt-1 inline-block text-sm text-accent hover:underline">{{ $company->website }}</a>
            @endif
        </div>
        @can('update', $company)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('companies.edit', $company) }}"
               class="inline-flex items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-1.5 text-sm font-semibold text-ink hover:bg-raised">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>
    @if ($company->notes)
        <p class="mt-4 border-t border-line pt-4 text-sm text-body">{{ $company->notes }}</p>
    @endif
</div>
