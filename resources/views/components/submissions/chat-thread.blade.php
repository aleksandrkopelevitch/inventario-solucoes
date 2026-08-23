{{-- The interview. The assistant's turn is the page's main content now, so it
     reads as prose (Markdown, full width) rather than as a card in a rail;
     the person's turn stays a bubble, which is what keeps the alternation
     legible when both are long. --}}
<div id="{{ $domId }}" class="flex flex-col gap-6">
    @foreach ($messages as $message)
        @if ($message->role === 'user')
            <div class="ml-auto max-w-[80%] whitespace-pre-line rounded-2xl rounded-br-sm bg-accent px-4 py-2.5 text-sm leading-relaxed text-white shadow-sm">
                {{ $message->content }}
            </div>
        @else
            <div class="flex max-w-full gap-3">
                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-lime text-lime-ink shadow-sm">
                    <x-heroicon-o-sparkles class="size-4" />
                </span>

                <div class="min-w-0 flex-1">
                    @if (($message->meta['status'] ?? null) === 'failed')
                        <p class="text-sm leading-relaxed text-crit">{{ $message->content }}</p>
                    @else
                        <x-ui.markdown :text="$message->content" class="text-sm leading-relaxed text-ink" />
                    @endif

                    @if ($message->hasDrafts())
                        <div class="mt-3 flex flex-col gap-2 rounded-card border border-line bg-raised p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Rascunho para {{ count($message->drafts) === 1 ? '1 seção' : count($message->drafts) . ' seções' }}
                                </p>

                                @if ($message->applied_at !== null)
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-accent-line bg-accent-soft px-2.5 py-1 text-xs font-medium text-accent">
                                        <x-heroicon-o-check class="size-3.5" /> Aplicado
                                    </span>
                                @else
                                    <form id="apply-draft-{{ $message->id }}">@csrf</form>
                                    <x-forms.button form="apply-draft-{{ $message->id }}" class="!px-3 !py-1.5 !text-xs"
                                        data-ak-ajax="apply-draft-{{ $message->id }}"
                                        data-ak-action="{{ route('submissions.chat.messages.apply', [$chat->submission, $message]) }}">
                                        <x-heroicon-o-arrow-down-tray class="size-3.5" /> Aplicar às seções
                                    </x-forms.button>
                                @endif
                            </div>

                            @foreach ($message->drafts as $draft)
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-ink">
                                        {{ \App\Enums\SubmissionSectionKey::tryFrom($draft['key'])?->label() ?? $draft['key'] }}
                                    </summary>
                                    <div class="mt-1.5 border-l-2 border-line pl-3">
                                        <x-ui.markdown :text="$draft['markdown']" />
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endforeach

    @if ($awaiting)
        <div data-ak-cati-chat-poll="{{ $statusUrl }}" class="flex items-center gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-lime text-lime-ink shadow-sm">
                <x-heroicon-o-sparkles class="size-4" />
            </span>
            <span class="flex items-center gap-2.5 text-sm text-muted">
                <span class="flex gap-1">
                    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
                </span>
                Preparando a próxima pergunta…
            </span>
        </div>
    @endif

    @if ($messages->isEmpty() && ! $awaiting)
        {{-- SeedSubmissionChatOpening gives every conversation a first line, so
             this only shows if that seeding was skipped (an old chat, a failed
             write). Still worth having: a genuinely blank thread with no
             invitation is the worst version of this screen. --}}
        <p class="text-sm text-muted">
            Conte o que a solução faz e o que muda. Eu já sei o que está no catálogo — vou perguntar só o resto.
        </p>
    @endif
</div>
