<div id="{{ $domId }}">
    @if ($submission->decided_at)
        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display text-sm font-bold text-ink">Deliberação</h2>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $submission->status->badgeClass() }}">
                    {{ $submission->status->label() }}
                </span>
                <span class="text-xs text-muted">
                    {{ $submission->decided_at->format('d/m/Y') }}
                    @if ($submission->decidedBy) · {{ $submission->decidedBy->name }} @endif
                </span>
                @if ($submission->promoted_at)
                    <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-accent">
                        <x-heroicon-o-check-circle class="size-4" /> Publicada na documentação da solução
                    </span>
                @endif
            </div>

            <div class="mt-2"><x-ui.markdown :text="$submission->decision" /></div>

            @if ($conditions !== [])
                @php
                    $open = collect($conditions)->reject(fn ($condition) => $condition['done'] ?? false)->count();
                @endphp

                <div class="mt-3 border-t border-line pt-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted">
                        Ressalvas
                        <span class="ml-1 normal-case tracking-normal text-faint">
                            {{ $open === 0 ? 'todas cumpridas' : $open . ' em aberto' }}
                        </span>
                    </p>

                    <ul class="flex flex-col gap-1.5 pl-0">
                        @foreach ($conditions as $index => $condition)
                            @php $done = $condition['done'] ?? false; @endphp
                            <li class="group/row flex items-start gap-2">
                                @if ($canEdit)
                                    {{-- A ressalva nobody can close is a ressalva nobody
                                         follows up on, which is the whole reason these are
                                         a list and not a paragraph. --}}
                                    <form id="condition-{{ $index }}">@csrf</form>
                                    <x-forms.button form="condition-{{ $index }}" variant="ghost"
                                        class="!size-5 !min-h-0 !shrink-0 !p-0"
                                        aria-label="{{ $done ? 'Reabrir ressalva' : 'Marcar ressalva como cumprida' }}"
                                        data-ak-ajax="condition-{{ $index }}"
                                        data-ak-action="{{ route('submissions.conditions.toggle', [$submission, $index]) }}">
                                        @if ($done)
                                            <x-heroicon-s-check-circle class="size-5 text-accent" />
                                        @else
                                            <x-heroicon-o-flag class="size-4 text-cat-amber" />
                                        @endif
                                    </x-forms.button>
                                @else
                                    <x-dynamic-component :component="$done ? 'heroicon-s-check-circle' : 'heroicon-o-flag'"
                                        class="mt-0.5 size-4 shrink-0 {{ $done ? 'text-accent' : 'text-cat-amber' }}" />
                                @endif

                                <span @class(['text-sm', 'text-muted line-through' => $done, 'text-ink' => ! $done])>
                                    {{ $condition['text'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
