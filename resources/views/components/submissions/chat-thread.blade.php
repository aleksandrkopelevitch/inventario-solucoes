<div id="{{ $domId }}" class="flex flex-col gap-4">
    @forelse ($messages as $message)
        @if ($message->role === 'user')
            <div class="ml-auto max-w-[85%] whitespace-pre-line rounded-card rounded-br-sm bg-accent px-4 py-2.5 text-sm leading-relaxed text-white shadow-sm">
                {{ $message->content }}
            </div>
        @else
            <div class="mr-auto w-full max-w-[95%] rounded-card rounded-bl-sm border border-line bg-surface p-4 shadow-card">
                <div class="mb-2.5 flex items-center gap-2">
                    <span class="flex size-6 items-center justify-center rounded-lg bg-lime text-lime-ink shadow-sm">
                        <x-heroicon-o-sparkles class="size-3.5" />
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted">Preparação para o CATI</span>
                </div>

                <p @class([
                    'whitespace-pre-line text-sm leading-relaxed',
                    'text-crit' => ($message->meta['status'] ?? null) === 'failed',
                    'text-ink' => ($message->meta['status'] ?? null) !== 'failed',
                ])>{{ $message->content }}</p>

                @if ($message->hasDrafts())
                    <div class="mt-3 flex flex-col gap-2 rounded-field border border-line bg-raised p-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted">
                            Rascunho para {{ count($message->drafts) === 1 ? '1 seção' : count($message->drafts) . ' seções' }}
                        </p>

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

                        @if ($message->applied_at !== null)
                            <span class="inline-flex items-center gap-1.5 self-start rounded-full border border-accent-line bg-accent-soft px-2.5 py-1 text-xs font-medium text-accent">
                                <x-heroicon-o-check class="size-3.5" /> Aplicado
                            </span>
                        @else
                            <form id="apply-draft-{{ $message->id }}">@csrf</form>
                            <x-forms.button form="apply-draft-{{ $message->id }}" variant="glass" class="self-start !px-3 !py-1.5 !text-xs"
                                data-ak-ajax="apply-draft-{{ $message->id }}"
                                data-ak-action="{{ route('submissions.chat.messages.apply', [$chat->submission, $message]) }}">
                                <x-heroicon-o-arrow-down-tray class="size-3.5" /> Aplicar às seções
                            </x-forms.button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @empty
        <p class="text-sm text-muted">
            Conte o que a solução faz e o que muda. Eu já sei o que está no catálogo — vou perguntar só o resto.
        </p>
    @endforelse

    @if ($awaiting)
        <div data-ak-cati-chat-poll="{{ $statusUrl }}"
             class="mr-auto flex items-center gap-2.5 rounded-card rounded-bl-sm border border-line bg-surface px-4 py-3 text-sm text-muted">
            <span class="flex gap-1">
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
            </span>
            Preparando a próxima pergunta…
        </div>
    @endif
</div>
