@props([
    'submission',
    'chat',
])

@php
    $menuId = 'cati-attach-menu';

    // Everything cati-chat.js needs. The paste threshold is served from
    // config so the client and StoreSubmissionSourceRequest can't drift.
    $config = [
        'attachUrl'      => route('submissions.sources.store', $submission),
        'pasteThreshold' => (int) config('services.cati.paste_threshold_chars'),
        'maxPastedChars' => (int) config('services.cati.max_pasted_chars'),
    ];
@endphp

{{-- The interview's composer: one rounded box holding the submission's
     material, the message and the toolbar — the Claude/ChatGPT shape.

     Unlike the flowSpec composer there is no "staged" mode: the conversation
     always exists by the time this renders (SubmissionController::chatFor
     opens it on the GET), so every attach goes straight to the server and
     comes back as a slot. Material is submission-scoped, not chat-scoped: it
     outlives the conversation and is what the deck and the ticket are also
     built from.

     THE BOX IS A <div>, NOT THE <form>, and the chips sit OUTSIDE the form on
     purpose. Each chip carries its own hidden DELETE form, and a <form> nested
     inside another is invalid HTML: the parser drops the inner start tag
     entirely and re-parents its children, so `document.getElementById(...)`
     returns null and ajax-post.js dies on `new FormData(null)` — a ✕ that
     throws instead of removing anything, with nothing wrong in the source.

     Behaviour lives in cati-chat.js (data-ak-cati-*). --}}
<div data-ak-cati-composer="{{ json_encode($config) }}"
     class="rounded-2xl border border-line-2 bg-surface shadow-card transition focus-within:border-accent-line focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)] data-[dragging=true]:border-accent data-[dragging=true]:bg-accent-soft">

    <x-submissions.composer-context :submission="$submission" />

    <form id="cati-chat-form" enctype="multipart/form-data">
        @csrf

        <x-forms.textarea data-ak-cati-chat-input name="message" rows="2"
            class="max-h-64 !resize-none !rounded-none !border-0 !bg-transparent !px-4 !py-3 !shadow-none focus:!border-0 focus:!shadow-none"
            placeholder="Responda em texto corrido, cole um documento inteiro ou solte um arquivo aqui." />

        {{-- `!hidden`, not `hidden`: x-forms.input's base classes include
             `block`, and which display utility wins is decided by Tailwind's
             output order, not by the order written here. --}}
        <x-forms.input type="file" name="file" multiple data-ak-cati-file-input class="!hidden"
            accept=".pdf,.pptx,.docx,.txt,.md,.csv,.json,.png,.jpg,.jpeg,.webp,.svg" />

        <div class="flex items-end justify-between gap-2 px-3 pb-3">
            <div class="relative">
                <x-forms.button type="button" variant="ghost" class="!p-2" title="Anexar material"
                    data-ak-toggle="{{ $menuId }}" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true">
                    <x-heroicon-o-paper-clip class="size-5" />
                </x-forms.button>

                <div id="{{ $menuId }}"
                     class="absolute bottom-full left-0 z-20 mb-2 hidden w-80 rounded-card border border-line bg-surface p-1.5 shadow-lg">
                    <x-forms.button type="button" variant="ghost" data-ak-cati-open-file
                        class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                        <x-heroicon-o-arrow-up-tray class="size-4 text-muted" />
                        <span class="flex flex-col items-start leading-tight">
                            <span>Arquivo do computador</span>
                            <span class="text-[11px] text-faint">Deck antigo, PDF, documento ou imagem</span>
                        </span>
                    </x-forms.button>

                    <div class="mt-1 border-t border-line px-3 pb-1 pt-2">
                        <x-forms.label for="cati-link-input" class="!text-[11px] !uppercase !tracking-wider !text-faint">
                            Link de referência
                        </x-forms.label>
                        <div class="mt-1 flex items-center gap-1.5">
                            {{-- A plain input, not a nested form (see the note
                                 above) — cati-chat.js reads the value and posts
                                 it. --}}
                            <x-forms.input type="url" id="cati-link-input" data-ak-cati-link-input
                                placeholder="https://…" class="!py-1.5 !text-xs" />
                            <x-forms.button type="button" variant="glass" class="!shrink-0 !px-2.5 !py-1.5 !text-xs"
                                data-ak-cati-link-add>
                                Anexar
                            </x-forms.button>
                        </div>
                        <p class="mt-1.5 text-[11px] leading-snug text-faint">
                            O conteúdo do link não é baixado — fica só como referência.
                        </p>
                    </div>

                    <p class="border-t border-line px-3 pb-1 pt-2 text-[11px] leading-snug text-faint">
                        Texto longo colado na caixa vira anexo automaticamente.
                    </p>
                </div>
            </div>

            <x-forms.button type="button" data-ak-cati-chat-send
                data-action="{{ route('submissions.chat.messages.store', $submission) }}">
                <x-heroicon-o-paper-airplane class="size-4" /> Enviar
            </x-forms.button>
        </div>
    </form>
</div>
