<x-layouts.layout :title="$person->name">
    <div class="mb-6 animate-ak-fade">
        <a href="{{ route('people.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Pessoas
        </a>
    </div>

    <div class="animate-ak-rise">
        <x-people.detail-header :person="$person" />
    </div>

    {{-- Both sections are their own updatable slots (see their View
         Components): they're edited in place here AND from the side panel's
         form, so both responses have to be able to refresh them. --}}
    <x-people.systems :person="$person" />

    {{-- Access sits with the other facts about this person. It is `manage`-gated
         inside the component, not here: an editor SEES whether this person can
         log in (a fact about the catalog) and cannot change it. --}}
    <x-people.access :person="$person" />

    <x-people.notes :person="$person" />
</x-layouts.layout>
