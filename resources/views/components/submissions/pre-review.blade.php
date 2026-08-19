<div id="{{ $domId }}" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-card">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="font-display text-sm font-bold text-ink">Prévia do comitê</h2>
        @if ($submission->pre_reviewed_at && ! $running)
            <span class="text-xs text-muted">{{ $submission->pre_reviewed_at->diffForHumans() }}</span>
        @endif
    </div>

    <p class="text-xs text-muted">
        Uma leitura cética da submissão, antes da reunião — o que a checagem automática não enxerga.
    </p>

    @if ($running)
        {{-- Presence of this marker is what keeps cati-chat.js polling. --}}
        <div data-ak-cati-chat-poll="{{ $statusUrl }}" class="flex items-center gap-2.5 text-sm text-muted">
            <span class="flex gap-1">
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
            </span>
            Lendo como o comitê leria…
        </div>
    @elseif ($submission->pre_reviewed_at && $findings === [])
        <p class="inline-flex items-center gap-1.5 text-sm text-accent">
            <x-heroicon-o-check-circle class="size-4" />
            Nada a objetar além do que já está no checklist.
        </p>
    @elseif ($findings !== [])
        <ul class="flex flex-col gap-3 pl-0">
            @foreach ($findings as $finding)
                <li class="flex gap-2.5">
                    <span @class([
                        'mt-1.5 size-1.5 shrink-0 rounded-full',
                        'bg-hot' => $finding['severity'] === 'alta',
                        'bg-cat-amber' => $finding['severity'] === 'media',
                        'bg-faint' => $finding['severity'] === 'baixa',
                    ])></span>
                    <span class="min-w-0">
                        <span class="block text-sm leading-snug text-ink">{{ $finding['text'] }}</span>
                        <span class="block text-xs text-faint">
                            {{ \App\Enums\SubmissionSectionKey::tryFrom($finding['section'])?->label() ?? $finding['section'] }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit && ! $running)
        <form id="pre-review-form">@csrf</form>
        <x-forms.button form="pre-review-form" variant="glass" class="self-start !px-3 !py-1.5 !text-xs"
            data-ak-ajax="pre-review-form"
            data-ak-action="{{ route('submissions.pre-review.store', $submission) }}">
            <x-heroicon-o-eye class="size-3.5" />
            {{ $submission->pre_reviewed_at ? 'Rodar de novo' : 'Rodar prévia' }}
        </x-forms.button>
    @endif
</div>
