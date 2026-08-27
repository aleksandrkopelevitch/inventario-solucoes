@php
    // Status badge classes (documented vs. pending), reused for both the
    // caderno and its pages.
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
                @php ($notebook = $group['notebook'])
                <div class="rounded-card border border-line bg-surface shadow-card">
                    {{-- Group header: the caderno, and the solutions it
                         documents. That second line is what the hub is for now:
                         coverage is a statement about solutions, and a caderno
                         is how a solution gets covered. --}}
                    <div class="flex items-start justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <a href="{{ $notebook['url'] }}" class="truncate font-display text-[15px] font-semibold text-ink no-underline transition-colors hover:text-accent">
                                    {{ $notebook['name'] }}
                                </a>
                                <span @class($badge($notebook['hasDocs']))>
                                    {{ $notebook['hasDocs'] ? 'Documentado' : 'Sem conteúdo' }}
                                </span>
                            </div>

                            @if ($notebook['solutions'] !== [])
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @foreach ($notebook['solutions'] as $solution)
                                        <a href="{{ $solution['url'] }}"
                                            class="inline-flex max-w-56 items-center rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-ink no-underline ring-1 ring-accent-line transition-colors hover:bg-accent-line">
                                            <span class="truncate">{{ $solution['name'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-1.5 text-xs text-muted">Não vinculado a nenhuma solução.</p>
                            @endif
                        </div>

                        <a href="{{ $notebook['url'] }}"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                            <x-heroicon-o-pencil-square class="size-4" />
                            Abrir
                        </a>
                    </div>

                    {{-- The caderno's pages. Alphabetical, deliberately — see
                         DocumentationCoverageService::groups() for why this is
                         not the tree's reading order. --}}
                    @if ($group['pages']->isNotEmpty())
                        <ul class="divide-y divide-line border-t border-line">
                            @foreach ($group['pages'] as $page)
                                <li>
                                    <a href="{{ $page['url'] }}" class="flex items-center justify-between gap-3 px-4 py-2.5 pl-6 text-sm no-underline transition-colors hover:bg-raised">
                                        <span class="flex min-w-0 items-center gap-2 text-ink">
                                            <x-heroicon-o-document-text class="size-3.5 shrink-0 text-faint" />
                                            <span class="truncate">{{ $page['title'] }}</span>
                                            {{-- A page that also carries a
                                                 drawing. Same marker as the
                                                 pages rail uses. --}}
                                            @if ($page['hasDiagram'])
                                                <x-heroicon-o-share class="size-3.5 shrink-0 text-accent" title="Tem diagrama vinculado" />
                                            @endif
                                        </span>
                                        <span @class($badge($page['hasDocs']))>
                                            {{ $page['hasDocs'] ? 'Documentado' : 'Sem conteúdo' }}
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
