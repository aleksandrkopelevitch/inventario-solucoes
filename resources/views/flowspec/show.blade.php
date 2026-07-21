<x-layouts.layout :title="$chat->title" :fluid="true">
    <div class="flex min-h-0 flex-1">
        <x-flowspec.chat-list :chats="$chats" :current="$chat" />

        <section class="flex min-h-0 min-w-0 flex-1 flex-col">
            <x-flowspec.top-bar>
                <p class="truncate text-sm font-semibold text-ink">{{ $chat->title }}</p>
                @if ($chat->integration)
                    <p class="truncate text-xs text-muted">Vinculado à integração <span class="font-medium text-ink">{{ $chat->integration->name }}</span></p>
                @endif
            </x-flowspec.top-bar>

            {{-- Scrollable thread (full width) --}}
            <div data-ak-fs-scroll class="min-h-0 flex-1 overflow-y-auto">
                <div class="w-full px-4 py-6 md:px-6">
                    <x-flowspec.thread :chat="$chat" />
                </div>
            </div>

            {{-- Composer — pinned to the bottom, full width --}}
            <div class="border-t border-line bg-canvas/50 px-4 py-3 md:px-6">
                <x-flowspec.composer
                    formId="flowspec-message-form"
                    :action="route('flowspec.messages.store', $chat)"
                    messageId="flowspec-message-input"
                    referenceId="flowspec-reference-input"
                    submitLabel="Enviar"
                    placeholder="Peça um ajuste no flowSpec ou descreva a próxima geração…" />
            </div>
        </section>
    </div>
</x-layouts.layout>
