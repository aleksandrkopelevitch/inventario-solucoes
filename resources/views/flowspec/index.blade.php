<x-layouts.layout title="Especialista em Integrações" :fluid="true">
    <div class="flex min-h-0 flex-1">
        <x-flowspec.chat-list :chats="$chats" />

        <section class="flex min-h-0 min-w-0 flex-1 flex-col">
            <x-flowspec.top-bar>
                <p class="truncate text-sm font-semibold text-ink">Especialista em Integrações</p>
            </x-flowspec.top-bar>

            {{-- Greeting (scrolls); composer stays pinned below --}}
            <div class="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto px-4 py-8 text-center">
                {{-- Gradient "glow" ring (see [[radiant-protocol-redesign]]) behind
                     the icon badge — this page is a fluid chat UI, not a list page,
                     so a full <x-ui.hero-panel> header doesn't fit; this is the
                     scaled-down equivalent touch. --}}
                <div class="relative mb-4 flex size-12 items-center justify-center rounded-2xl bg-sidebar text-white shadow-[0_0_0_3px_var(--color-glow-b,transparent)]">
                    <div class="pointer-events-none absolute -inset-2 -z-10 rounded-full opacity-40 blur-lg" style="background: conic-gradient(from 140deg, var(--color-glow-a), var(--color-glow-b), var(--color-glow-c), var(--color-glow-a))"></div>
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
