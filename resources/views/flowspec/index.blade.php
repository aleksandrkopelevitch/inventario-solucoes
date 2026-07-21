<x-layouts.layout title="Gerador de flowSpec" :fluid="true">
    <div class="flex min-h-0 flex-1">
        <x-flowspec.chat-list :chats="$chats" />

        <section class="flex min-h-0 min-w-0 flex-1 flex-col">
            <x-flowspec.top-bar>
                <p class="truncate text-sm font-semibold text-ink">Gerador de flowSpec</p>
            </x-flowspec.top-bar>

            {{-- Greeting (scrolls); composer stays pinned below --}}
            <div class="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto px-4 py-8 text-center">
                <div class="mb-4 flex size-12 items-center justify-center rounded-2xl bg-sidebar text-white">
                    <x-heroicon-o-cpu-chip class="size-6" />
                </div>
                <h1 class="font-display text-[26px] font-semibold leading-tight text-ink">Gerar um flowSpec</h1>
                <p class="mt-1.5 max-w-lg text-sm text-muted">Descreva a integração e gere um pipeline Digibee pronto para colar no canvas, com base na documentação do inventário. Cite os sistemas pelo nome (SVL, IAM…) — use <x-heroicon-o-paper-clip class="inline size-4 align-text-bottom" /> para anexar documentação específica ou um flowSpec de referência.</p>
            </div>

            {{-- Composer — pinned to the bottom, full width --}}
            <div class="border-t border-line bg-canvas/50 px-4 py-3 md:px-6">
                <x-flowspec.composer
                    formId="flowspec-new-chat-form"
                    :action="route('flowspec.store')"
                    messageId="flowspec-new-message"
                    referenceId="flowspec-new-reference"
                    submitLabel="Gerar flowSpec"
                    placeholder="Ex.: com base na documentação do SVL e do IAM, crie um flowSpec que receba o colaborador, gerencie cache de token JWT por 30 min e faça POST no SVL." />
            </div>
        </section>
    </div>
</x-layouts.layout>
