<x-layouts.layout :title="$person->name">
    <div class="mb-6">
        <a href="{{ route('people.index') }}" class="text-sm text-accent hover:underline">&larr; Pessoas</a>
    </div>

    <x-people.detail-header :person="$person" />

    <div class="mt-5 rounded-card border border-line bg-surface p-6 shadow-card">
        <h2 class="font-display text-[18px] font-semibold text-ink">Sistemas ({{ $person->solutions->count() }})</h2>
        @if ($person->solutions->isEmpty())
            <p class="mt-2 text-sm text-muted">Nenhum sistema vinculado.</p>
        @else
            <ul class="mt-3 divide-y divide-line">
                @foreach ($person->solutions as $solution)
                    <li class="flex items-center justify-between py-2.5 text-sm">
                        <a href="{{ route('solutions.show', $solution) }}" class="font-medium text-ink hover:text-accent">{{ $solution->name }}</a>
                        <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink ring-1 ring-accent-line">{{ \App\Enums\PersonSolutionRole::from($solution->pivot->role)->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.layout>
