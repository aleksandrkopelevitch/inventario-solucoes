@php
    $contacts = collect([['E-mail', $person->email], ['Telefone', $person->phone]])
        ->concat($person->contacts->map(fn ($c) => [$c->type->label(), $c->value]))
        ->filter(fn ($c) => filled($c[1]));
@endphp

<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-6 shadow-card">
    <div class="flex flex-wrap items-start gap-4">
        <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="lg" />
        <div class="min-w-0 flex-1">
            <h1 class="font-display text-[26px] font-semibold leading-tight text-ink">{{ $person->name }}</h1>
            @if ($person->job_title)<p class="mt-0.5 text-sm text-muted">{{ $person->job_title }}</p>@endif
            @if ($person->company)
                <a href="{{ route('companies.show', $person->company) }}" class="mt-1 inline-block text-sm text-accent hover:underline">{{ $person->company->name }}</a>
            @endif
        </div>
        @can('update', $person)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.edit', $person) }}"
               class="inline-flex items-center gap-2 rounded-field border border-line-2 bg-surface px-3 py-1.5 text-sm font-semibold text-ink hover:bg-raised">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>

    @if ($contacts->isNotEmpty())
        <div class="mt-5 flex flex-wrap gap-x-8 gap-y-2 border-t border-line pt-5 text-sm">
            @foreach ($contacts as [$label, $value])
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</div>
                    <div class="text-ink">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
