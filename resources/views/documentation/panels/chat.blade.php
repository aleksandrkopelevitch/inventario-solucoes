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

    <div class="border-t border-line px-6 py-4">
        {{-- Deliberately OUTSIDE #docs-chat-message-form: context-documents.blade.php
             renders one <form id="ctx-remove-{id}"> per attached document (needed so
             its data-ak-ajax remove button has a real HTMLFormElement to build
             FormData from). A <form> nested inside another <form> is invalid HTML —
             browsers close the outer form early once 2+ inner forms are present,
             which silently pushed the composer textarea/button out of
             #docs-chat-message-form and broke input.closest('form') in the
             Enter-to-send handler (docs-chat.js) even though the click handler kept
             working via its `|| document` fallback. --}}
        <div class="mb-3">
            <div class="mb-2 flex items-baseline gap-1.5">
                <x-forms.label class="!mb-0">Documentos de contexto</x-forms.label>
                <span class="text-xs text-muted">da solução</span>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                {{-- Updatable slot: refreshed by context.store/destroy. --}}
                <x-documentation.context-documents :notebook="$notebook" />

                {{-- The file uploads automatically on selection (docs-chat.js) —
                     no separate "Anexar" click, which users kept skipping. The
                     native input is visually hidden; the label is the icon
                     trigger and forwards its click to it via `for`. --}}
                <label for="docs-chat-context-file"
                    class="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-full border border-dashed border-line-2 text-muted transition-colors hover:border-accent-line hover:bg-accent-soft hover:text-accent"
                    title="Anexar documento de contexto" aria-label="Anexar documento de contexto">
                    <x-heroicon-o-plus class="size-4" />
                </label>
                <x-forms.file id="docs-chat-context-file" data-ak-context-upload data-action="{{ $contextStoreUrl }}" class="!sr-only"
                    accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.md,.csv,.json,.yaml,.yml" />
            </div>
            <span data-ak-context-uploading class="mt-2 hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
                <span class="size-3 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                Enviando documento…
            </span>
        </div>

        <form id="docs-chat-message-form">
            <div class="flex items-end gap-2">
                <x-forms.textarea data-ak-docs-chat-input rows="1"
                    class="max-h-40 min-h-[2.5rem] flex-1 resize-none !py-2.5"
                    placeholder="Peça para escrever, revisar ou pergunte algo sobre esta documentação…" />
                <x-forms.button type="button" data-ak-docs-chat-send data-action="{{ $sendUrl }}" class="!h-10 !w-10 shrink-0 !p-0" aria-label="Enviar">
                    <x-heroicon-o-paper-airplane class="size-5" />
                </x-forms.button>
            </div>
            <p class="mt-1.5 text-xs text-muted">Enter envia · Shift+Enter quebra linha.</p>
        </form>
    </div>
</div>
