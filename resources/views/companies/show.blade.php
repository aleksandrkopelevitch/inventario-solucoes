<x-layouts.layout :title="$company->name">
    <div class="mb-6 animate-ak-fade">
        <a href="{{ route('companies.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Empresas
        </a>
    </div>

    <div class="animate-ak-rise">
        <x-companies.detail-header :company="$company" />
    </div>

    {{-- Both cards are their own updatable slots: people and provided systems
         are attached/detached in place on them (`Companies\People`,
         `Companies\ProvidedSolutions`). --}}
    <div class="animate-ak-rise mt-5 grid items-start gap-5 lg:grid-cols-2" style="animation-delay: 90ms">
        <x-companies.people :company="$company" />
        <x-companies.provided-solutions :company="$company" />
    </div>
</x-layouts.layout>
