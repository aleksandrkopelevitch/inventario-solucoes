@php
    // Status badge classes (documented vs. pending), reused for both
    // solution and integration — same pair used in the related-docs index.
    $badge = fn (bool $hasDocs) => [
        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        'bg-accent-soft text-accent' => $hasDocs,
        'bg-raised text-muted' => ! $hasDocs,
    ];
@endphp

<div id="{{ $domId }}">
    @if ($groups->isEmpty())
        <p class="rounded-card border border-dashed border-line bg-surface px-4 py-12 text-center text-sm text-muted">
            Nenhum item corresponde aos filtros.
        </p>
    @else
        <div class="space-y-3">
            @foreach ($groups as $group)
                @php ($solution = $group['solution'])
                <div class="rounded-card border border-line bg-surface shadow-card">
                    {{-- Group header: the solution --}}
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <a href="{{ $solution['showUrl'] }}" class="truncate font-display text-[15px] font-semibold text-ink no-underline hover:text-accent">
                                {{ $solution['name'] }}
                            </a>
                            @if ($solution['showStatus'])
                                <span @class($badge($solution['hasDocs']))>
                                    {{ $solution['hasDocs'] ? 'Documentado' : 'Sem documentação' }}
                                </span>
                            @endif
                        </div>
                        <a href="{{ $solution['url'] }}"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                            <x-heroicon-o-pencil-square class="size-4" />
                            Documentação
                        </a>
                    </div>

                    {{-- Solution's integrations --}}
                    @if ($group['integrations']->isNotEmpty())
                        <ul class="divide-y divide-line border-t border-line">
                            @foreach ($group['integrations'] as $integration)
                                <li>
                                    <a href="{{ $integration['url'] }}" class="flex items-center justify-between gap-3 px-4 py-2.5 pl-6 text-sm no-underline hover:bg-raised">
                                        <span class="flex min-w-0 items-center gap-2 text-ink">
                                            <x-heroicon-o-arrows-right-left class="size-3.5 shrink-0 text-faint" />
                                            <span class="truncate">{{ $integration['name'] }}</span>
                                        </span>
                                        <span @class($badge($integration['hasDocs']))>
                                            {{ $integration['hasDocs'] ? 'Documentado' : 'Sem documentação' }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
