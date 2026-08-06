@php
    $contacts = collect([['E-mail', $person->email], ['Telefone', $person->phone]])
        ->concat($person->contacts->map(fn ($c) => [$c->type->label(), $c->value]))
        ->filter(fn ($c) => filled($c[1]));
@endphp

<div id="{{ $domId }}" class="overflow-hidden rounded-bento border border-line bg-surface shadow-card">
    {{-- Identity strip on the gradient — first child of the overflow-hidden
         card, same pattern as solutions/detail-header.blade.php (see its
         comment for why this doesn't need its own radius). --}}
    <div class="relative flex flex-wrap items-start gap-4 p-6"
         style="background: linear-gradient(135deg, color-mix(in srgb, var(--color-glow-a) 32%, white) 0%, color-mix(in srgb, var(--color-lime-soft) 75%, white) 100%)">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[3px]"
             style="background: linear-gradient(90deg, var(--color-glow-a), var(--color-accent), var(--color-ink))"></div>

        <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="lg" class="relative shadow-sm" />
        <div class="relative min-w-0 flex-1">
            <h1 class="font-display text-[28px] font-bold leading-tight tracking-tight text-[color:var(--color-glow-ink)]">{{ $person->name }}</h1>
            @if ($person->job_title)<p class="mt-0.5 text-sm text-[color:var(--color-glow-ink)]/70">{{ $person->job_title }}</p>@endif
            @if ($person->company)
                <a href="{{ route('companies.show', $person->company) }}" class="mt-1 inline-block text-sm text-[color:var(--color-glow-ink)]/80 hover:text-[color:var(--color-glow-ink)] hover:underline">{{ $person->company->name }}</a>
            @endif
        </div>
        @can('update', $person)
            <a href="#" data-ak-panel-open data-ak-panel-url="{{ route('people.edit', $person) }}"
               class="relative inline-flex items-center gap-2 rounded-field border border-white/50 bg-white/60 px-3 py-1.5 text-sm font-semibold text-[color:var(--color-glow-ink)] backdrop-blur hover:bg-white/90">
                <x-heroicon-o-pencil-square class="size-4" /> Editar
            </a>
        @endcan
    </div>

    @if ($contacts->isNotEmpty())
        <div class="flex flex-wrap gap-x-8 gap-y-2 border-t border-line p-6 text-sm">
            @foreach ($contacts as [$label, $value])
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</div>
                    <div class="text-ink">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
