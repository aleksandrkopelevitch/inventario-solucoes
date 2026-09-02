<div class="flex h-full flex-col">
    <div class="flex items-center justify-between gap-3 bg-btn px-6 py-4">
        <div class="flex min-w-0 items-center gap-2.5">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-lime text-lime-ink shadow-sm">
                <x-heroicon-o-sparkles class="size-5" />
            </span>
            <div class="min-w-0">
                <h2 class="truncate text-sm font-bold text-white">Especialista em Documentação</h2>
                <p class="mt-0.5 truncate text-xs text-white/55" title="{{ $targetLabel }}">{{ $targetLabel }}</p>
            </div>
        </div>
        <x-forms.button type="button" variant="ghost" data-ak-panel-close
            class="!h-8 !w-8 !p-0 shrink-0 !text-white/60 hover:!bg-white/10 hover:!text-white" aria-label="Fechar">
            <x-heroicon-o-x-mark class="size-5" />
        </x-forms.button>
    </div>

    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5" data-ak-docs-chat-scroll>
        @include('documentation.partials._requirements-checklist')

        <x-documentation.chat-thread :chat="$chat" />
    </div>

    {{-- Composer — ONE rounded box holding this turn's context, the message and
         a toolbar, the same shape as x-flowspec.composer (the Especialista em
         Integrações), because it answers the same gesture. It replaced two
         labelled rows of chips stacked above the textarea: attaching was two
         separate "+" buttons in two separate sections, and on a docked panel
         320px wide those rows pushed the textarea to the floor.

         There are exactly TWO ways to add context and the [+] menu has exactly
         two items to match: a file from the computer (which becomes the
         CADERNO's document, shared by every page in it) and a documentation
         page of any caderno (which is a statement about THIS conversation and
         is recorded on the message, `context_page_ids`). A long paste is the
         third door and needs no item — it becomes a document on its own.

         The pills row sits deliberately OUTSIDE #docs-chat-message-form:
         context-documents.blade.php renders one <form id="ctx-remove-{id}"> per
         attached document (needed so its data-ak-ajax remove button has a real
         HTMLFormElement to build FormData from). A <form> nested inside another
         <form> is invalid HTML — browsers close the outer form early once 2+
         inner forms are present, which silently pushed the composer
         textarea/button out of #docs-chat-message-form and broke
         input.closest('form') in the Enter-to-send handler (docs-chat.js) even
         though the click handler kept working via its `|| document` fallback.
         Being inside the BOX and outside the FORM is what lets the pills read
         as part of the composer without reintroducing that. --}}
    <div class="border-t border-line px-6 py-4">
        <div class="rounded-2xl border border-line-2 bg-surface shadow-card transition focus-within:border-accent-line focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]">
            {{-- Both kinds of context, in one wrapping row. Hidden while empty
                 (docs-chat.js::paintContextRow, which counts [data-ak-ctx-pill]
                 — `:empty` can't see through the slot's `display: contents`).
                 They are told apart by TONE, the same way the flowSpec composer
                 does it: raised/neutral for material somebody brought, accent
                 for documentation already in the inventory. --}}
            <div data-ak-docs-chat-context class="hidden flex-wrap items-center gap-1.5 px-3 pt-3">
                {{-- Updatable slot: refreshed by context.store/destroy. --}}
                <x-documentation.context-documents :notebook="$notebook" />

                {{-- Chips rendered by docs-chat.js. The picker is a modal rather
                     than a panel because the list spans every caderno and this
                     panel is 320px wide at its narrowest. --}}
                <div data-ak-context-pages class="contents"></div>
            </div>

            {{-- The composer's config, on the form. `attr="{{ json_encode(...) }}"`
                 and never `@json()`: the latter silently fails to compile inside
                 a component tag's attributes, and this form may yet become one.
                 `pasteThreshold` is served from config so docs-chat.js and
                 StoreContextDocumentRequest cannot drift on where "long" starts. --}}
            <form id="docs-chat-message-form"
                  data-ak-docs-chat-composer="{{ json_encode([
                      'contextStoreUrl' => $contextStoreUrl,
                      'pasteThreshold'  => (int) config('services.documentation_ai.paste_threshold_chars'),
                  ]) }}">
                <x-forms.textarea data-ak-docs-chat-input rows="2"
                    class="max-h-52 min-h-[3.25rem] !resize-none !rounded-none !border-0 !bg-transparent !px-4 !py-3 !shadow-none focus:!border-0 focus:!shadow-none"
                    placeholder="Peça para escrever, revisar ou pergunte algo sobre esta documentação…" />

                {{-- The file uploads on selection (docs-chat.js) — no separate
                     "Anexar" click, which users kept skipping. `!hidden` and not
                     `hidden`: x-forms.file's own base classes include `block`,
                     and which display utility wins is decided by Tailwind's
                     output order, not by the order written here. --}}
                <x-forms.file id="docs-chat-context-file" data-ak-context-upload data-action="{{ $contextStoreUrl }}"
                    class="!hidden" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.md,.csv,.json,.yaml,.yml" />

                <div class="flex items-center justify-between gap-2 px-3 pb-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="relative shrink-0">
                            <x-forms.button type="button" variant="ghost"
                                class="!size-8 !rounded-full !p-0"
                                data-ak-toggle="docs-chat-attach-menu" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                                title="Anexar contexto" aria-label="Anexar contexto">
                                <x-heroicon-o-plus class="size-5" />
                            </x-forms.button>

                            <div id="docs-chat-attach-menu" data-ak-docs-chat-attach-menu
                                 class="absolute bottom-full left-0 z-20 mb-2 hidden w-64 rounded-card border border-line bg-surface p-1.5 shadow-lg">
                                <x-forms.button type="button" variant="ghost" data-ak-docs-chat-attach-file
                                    class="!w-full !justify-start !px-3 !py-2 !text-left !font-normal !text-body">
                                    <x-heroicon-o-arrow-up-tray class="size-4 shrink-0 text-muted" />
                                    <span class="flex flex-col items-start leading-tight">
                                        <span>Arquivo do computador</span>
                                        <span class="text-[11px] text-faint">Vale para todo o caderno</span>
                                    </span>
                                </x-forms.button>

                                {{-- Opens the picker modal (docs-chat.js). It is
                                     the same trigger as before, moved in here. --}}
                                <x-forms.button type="button" variant="ghost"
                                    data-ak-context-page-add data-action="{{ $contextPagesUrl }}"
                                    class="!w-full !justify-start !px-3 !py-2 !text-left !font-normal !text-body">
                                    <x-heroicon-o-document-text class="size-4 shrink-0 text-muted" />
                                    <span class="flex flex-col items-start leading-tight">
                                        <span>Páginas de contexto</span>
                                        <span class="text-[11px] text-faint">De qualquer caderno</span>
                                    </span>
                                </x-forms.button>

                                <p class="px-3 pb-1 pt-1.5 text-[11px] leading-snug text-faint">
                                    Texto longo colado na caixa vira documento automaticamente.
                                </p>
                            </div>
                        </div>

                        <span data-ak-context-uploading class="hidden min-w-0 items-center gap-1.5 text-xs text-accent" aria-live="polite">
                            <span class="size-3 shrink-0 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                            <span class="truncate">Enviando documento…</span>
                        </span>
                    </div>

                    <x-forms.button type="button" data-ak-docs-chat-send data-action="{{ $sendUrl }}"
                        class="shrink-0 !px-3 !py-1.5" aria-label="Enviar">
                        <x-heroicon-o-paper-airplane class="size-4" />
                        Enviar
                    </x-forms.button>
                </div>
            </form>
        </div>

        <p class="mt-2 px-1 text-[11px] text-muted">Enter envia · Shift+Enter quebra linha.</p>
    </div>
</div>
