@php
    // Runtime-computed widths — the one case AGENTS.md keeps inline styles for.
    $confirmedPercent = $progress['total'] === 0 ? 0 : round($progress['confirmed'] / $progress['total'] * 100);
@endphp

<div id="{{ $domId }}" class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 shadow-card">
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-display text-sm font-bold text-ink">Documento</h2>
            <span class="text-xs tabular-nums text-muted">
                <span class="font-semibold text-ink">{{ $progress['answered'] }}</span> de {{ $progress['total'] }} seções
            </span>
        </div>

        {{-- Two tones on one track: what is written, and what a human has
             actually signed. A single bar would let eleven AI drafts read as a
             finished document. --}}
        <div class="relative mt-2 h-1.5 w-full overflow-hidden rounded-full bg-raised">
            <div class="absolute inset-y-0 left-0 rounded-full bg-cat-amber transition-[width] duration-300" style="width: {{ $progress['percent'] }}%"></div>
            <div class="absolute inset-y-0 left-0 rounded-full bg-cat-emerald transition-[width] duration-300" style="width: {{ $confirmedPercent }}%"></div>
        </div>

        <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <p class="text-[11px] text-faint">
                {{ $progress['confirmed'] }} de {{ $progress['total'] }} confirmada{{ $progress['confirmed'] === 1 ? '' : 's' }} por você.
            </p>

            {{-- The review step is the slowest one left: the interview drafts
                 six sections from one message, and finding which are still
                 unsigned meant scrolling the whole Documento tab. This says how
                 many are left and goes straight to the first. --}}
            @if ($nextUnconfirmed)
                <x-forms.button type="button" variant="ghost" class="!px-1.5 !py-0.5 !text-[11px] !font-medium"
                    data-ak-cati-goto-section="{{ $nextUnconfirmed['key'] }}"
                    title="Ir para {{ $nextUnconfirmed['label'] }}">
                    Revisar a próxima <x-heroicon-o-arrow-right class="size-3" />
                </x-forms.button>
            @endif
        </div>

        <ul class="mt-3 flex flex-col gap-0.5 pl-0">
            @foreach ($sections as $section)
                @php
                    $state = App\Enums\SubmissionSectionState::tryFrom($section['state']);
                @endphp
                <li>
                    {{-- Jumps to the section's card on the "Documento" tab —
                         cati-chat.js switches the tab first, since the card is
                         in a hidden panel until it does. --}}
                    <button type="button" data-ak-cati-goto-section="{{ $section['key'] }}"
                        class="flex w-full items-center gap-2 rounded-field px-1.5 py-1 text-left transition-colors hover:bg-raised">
                        @if ($state === App\Enums\SubmissionSectionState::Confirmed)
                            <x-heroicon-s-check-circle class="size-4 shrink-0 text-cat-emerald" />
                        @elseif ($state === App\Enums\SubmissionSectionState::Drafted)
                            <x-heroicon-o-pencil-square class="size-4 shrink-0 text-cat-amber" />
                        @else
                            <span class="ml-0.5 size-3 shrink-0 rounded-full ring-1 ring-line"></span>
                        @endif

                        <span @class([
                            'min-w-0 flex-1 truncate text-xs',
                            'text-ink'   => $section['answered'],
                            'text-muted' => ! $section['answered'],
                        ])>{{ $section['label'] }}</span>

                        @if ($section['mandatory'] && ! $section['answered'])
                            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wider text-hot">obrig.</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="border-t border-line pt-4">
        <h2 class="font-display text-sm font-bold text-ink">Já sabemos</h2>
        <p class="mt-0.5 text-xs text-muted">Vem do catálogo — o assistente não pergunta isso.</p>

        @if ($facts === [])
            <p class="mt-3 text-sm text-muted">
                Vincule uma solução do catálogo para o comitê já saber categoria, nuvem, criticidade e integrações.
            </p>
        @else
            <dl class="mt-3 flex flex-col gap-1.5">
                @foreach ($facts as $fact)
                    <div class="flex items-baseline gap-2 text-xs">
                        <dt class="shrink-0 text-muted">{{ $fact['label'] }}</dt>
                        <dd class="min-w-0 flex-1 truncate font-medium text-ink">{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
</div>
