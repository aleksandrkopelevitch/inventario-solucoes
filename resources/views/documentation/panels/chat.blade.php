<div class="flex h-full flex-col">
    <div class="flex items-start justify-between gap-3 border-b border-line px-6 py-4">
        <div class="min-w-0">
            <h2 class="flex items-center gap-2 text-base font-bold text-ink">
                <x-heroicon-o-sparkles class="size-5 text-accent" />
                Especialista em Documentação
            </h2>
            <p class="mt-0.5 truncate text-xs text-muted" title="{{ $targetLabel }}">{{ $targetLabel }}</p>
        </div>
        <x-forms.button type="button" variant="ghost" data-ak-panel-close class="!h-8 !w-8 !p-0 shrink-0" aria-label="Fechar">
            <x-heroicon-o-x-mark class="size-5" />
        </x-forms.button>
    </div>

    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5" data-ak-docs-chat-scroll>
        @include('documentation.partials._requirements-checklist')

        <x-documentation.chat-thread :chat="$chat" />
    </div>

    <div class="border-t border-line px-6 py-4">
        <form id="docs-chat-message-form">
            <div class="mb-3">
                <div class="mb-2 flex items-center justify-between">
                    <x-forms.label class="!mb-0">Documentos de contexto</x-forms.label>
                    <span class="text-xs text-muted">da solução</span>
                </div>

                {{-- Updatable slot: refreshed by context.store/destroy. --}}
                <x-documentation.context-documents :solution="$solution" />

                {{-- The file uploads automatically on selection (docs-chat.js) —
                     no separate "Anexar" click, which users kept skipping. --}}
                <div class="mt-3">
                    <x-forms.file data-ak-context-upload data-action="{{ $contextStoreUrl }}" class="w-full"
                        accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.md,.csv,.json,.yaml,.yml" />
                    <span data-ak-context-uploading class="mt-2 hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
                        <span class="size-3 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                        Enviando documento…
                    </span>
                </div>
            </div>

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
