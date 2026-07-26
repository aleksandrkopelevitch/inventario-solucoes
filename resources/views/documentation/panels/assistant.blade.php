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

    <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">
        <div>
            <x-forms.label for="docs-ai-prompt">O que o especialista deve escrever ou melhorar?</x-forms.label>
            <x-forms.textarea id="docs-ai-prompt" data-ak-docs-ai-prompt rows="5" class="mt-1.5"
                placeholder="Ex.: Descreva o fluxo de provisionamento de atendentes entre Senior HCM, Access One, SVL e SAP, com atores, ordem dos passos e pontos de atenção." />
            <p class="mt-1.5 text-xs text-muted">
                Se a página já tiver conteúdo, o especialista usa esse conteúdo junto com o pedido. Se estiver vazia, cria do zero.
            </p>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <x-forms.label class="!mb-0">Documentos de contexto</x-forms.label>
                <span class="text-xs text-muted">da solução</span>
            </div>

            {{-- Updatable slot: refreshed by context.store/destroy. --}}
            <x-documentation.context-documents :solution="$solution" />

            {{-- The file uploads automatically on selection (docs-ai.js) — no
                 separate "Anexar" click, which users kept skipping (picking a
                 file, then generating without ever attaching it). --}}
            <div class="mt-3">
                <x-forms.file data-ak-context-upload data-action="{{ $contextStoreUrl }}" class="w-full"
                    accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.md,.csv,.json,.yaml,.yml" />
                <span data-ak-context-uploading class="mt-2 hidden items-center gap-1.5 text-xs text-accent" aria-live="polite">
                    <span class="size-3 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                    Enviando documento…
                </span>
            </div>
            <p class="mt-1.5 text-xs text-muted">PDF, imagem ou texto (máx. 20 MB). O arquivo é anexado automaticamente ao ser escolhido; marque os que o especialista deve considerar.</p>
        </div>
    </div>

    <div class="border-t border-line px-6 py-4">
        <x-forms.button type="button" data-ak-docs-ai-generate data-action="{{ $generateUrl }}" class="w-full">
            <x-heroicon-o-sparkles class="size-5" />
            Gerar rascunho
        </x-forms.button>
        <p class="mt-2 text-center text-xs text-muted">O rascunho abre em uma tela de revisão, mostrando as alterações antes de aplicar.</p>
    </div>
</div>
