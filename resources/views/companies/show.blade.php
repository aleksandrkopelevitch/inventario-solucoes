<x-layouts.layout :title="$company->name">
    <div class="mb-6 animate-ak-fade">
        <a href="{{ route('companies.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Empresas
        </a>
    </div>

    <div class="animate-ak-rise">
        <x-companies.detail-header :company="$company" />
    </div>

    <div class="animate-ak-rise mt-5 grid gap-5 lg:grid-cols-2" style="animation-delay: 90ms">
        <div class="rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-[18px] font-semibold text-ink">Pessoas ({{ $company->people->count() }})</h2>
            @if ($company->people->isEmpty())
                <p class="mt-2 text-sm text-muted">Nenhuma pessoa cadastrada.</p>
            @else
                <ul class="mt-3 divide-y divide-line">
                    @foreach ($company->people as $person)
                        <li class="flex items-center gap-3 py-2.5">
                            <x-ui.avatar :name="$person->name" :src="$person->photo_path" size="sm" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('people.show', $person) }}" class="block truncate text-sm font-medium text-ink hover:text-accent">{{ $person->name }}</a>
                                @if ($person->job_title)<span class="text-xs text-muted">{{ $person->job_title }}</span>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas fornecidos ({{ $company->providedSolutions->count() }})</h2>
            @if ($company->providedSolutions->isEmpty())
                <p class="mt-2 text-sm text-muted">Nenhum sistema fornecido.</p>
            @else
                <ul class="mt-3 divide-y divide-line">
                    @foreach ($company->providedSolutions as $solution)
                        <li class="flex items-center justify-between py-2.5 text-sm">
                            <a href="{{ route('solutions.show', $solution) }}" class="font-medium text-ink hover:text-accent">{{ $solution->name }}</a>
                            <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent ring-1 ring-accent-line">{{ $solution->category_label }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-layouts.layout>
