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
                    <span class="text-xs font-semibold uppercase tracking-wide text-muted">Especialista em Documentação</span>
                </div>
                <p @class(['text-sm leading-relaxed whitespace-pre-line', 'text-crit' => ($message->meta['status'] ?? null) === 'failed', 'text-ink' => ($message->meta['status'] ?? null) !== 'failed'])>{{ $message->content }}</p>

                @if ($message->draft !== null)
                    {{-- Hidden source for the diff review — read by docs-chat.js, same
                         hidden-textarea convention as data-ak-docs-source/-markdown. --}}
                    <textarea data-ak-docs-chat-draft="{{ $message->id }}" hidden>{{ $message->draft }}</textarea>

                    <div class="mt-3">
                        @if ($message->applied_at !== null)
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-accent-line bg-accent-soft px-2.5 py-1 text-xs font-medium text-accent">
                                <x-heroicon-o-check class="size-3.5" /> Aplicado
                            </span>
                        @else
                            <x-forms.button type="button" variant="glass" class="!px-3 !py-1.5 !text-xs"
                                data-ak-docs-chat-view-draft="{{ $message->id }}"
                                data-apply-url="{{ route('solutions.docs.chat.messages.apply', [$chat->solution, $message]) }}">
                                <x-heroicon-o-arrows-right-left class="size-3.5" /> Ver alterações
                            </x-forms.button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @empty
        <p class="text-sm text-muted">Nenhuma mensagem ainda — descreva o que o especialista deve escrever ou pergunte algo sobre esta documentação.</p>
    @endforelse

    @if ($awaiting)
        <div data-ak-docs-chat-poll="{{ $statusUrl }}"
             class="mr-auto flex items-center gap-2.5 rounded-card rounded-bl-sm border border-line bg-surface px-4 py-3 text-sm text-muted">
            <span class="flex gap-1">
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
                <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
            </span>
            O especialista está pensando…
        </div>
    @endif
</div>

{{-- Review modal shell — docs-chat.js clones this into #main-modal when
     "Ver alterações" is clicked, injects the diff into
     [data-ak-docs-chat-review-body] and opens it. Applying loads the draft
     into the live editor (__akDocsSetMarkdown) — the user still has to
     Salvar; nothing is written to the page from here. --}}
<template data-ak-docs-chat-review-template>
    <div class="flex max-h-[82vh] flex-col">
        <div class="flex items-start justify-between gap-3 border-b border-line px-6 py-4">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-base font-bold text-ink">
                    <x-heroicon-o-sparkles class="size-5 text-accent" />
                    Revisar alterações propostas
                </h2>
                <p class="mt-0.5 text-xs text-muted">
                    Comparado com o conteúdo atual do editor, agora.
                    <span class="text-accent">Verde</span> = adicionado ·
                    <span class="text-crit">vermelho</span> = removido. Nada muda até você aplicar.
                </p>
            </div>
            <x-forms.button type="button" variant="ghost" data-ak-docs-chat-review-close
                class="!h-8 !w-8 shrink-0 !p-0" aria-label="Fechar">
                <x-heroicon-o-x-mark class="size-5" />
            </x-forms.button>
        </div>

        <div data-ak-docs-chat-review-body class="min-h-0 flex-1 overflow-y-auto px-6 py-4"></div>

        <div class="flex items-center justify-end gap-2 border-t border-line px-6 py-4">
            <x-forms.button type="button" variant="ghost" data-ak-docs-chat-review-close>
                Fechar
            </x-forms.button>
            <x-forms.button type="button" data-ak-docs-chat-apply>
                <x-heroicon-o-check class="size-5" />
                Aplicar no editor
            </x-forms.button>
        </div>
    </div>
</template>
