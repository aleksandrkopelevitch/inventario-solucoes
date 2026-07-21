<x-layouts.layout title="Gerador de flowSpec">
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Gerador de flowSpec</h1>
        <p class="mt-1 text-sm text-muted">Descreva a integração e gere um pipeline Digibee pronto para colar no canvas, com base na documentação do inventário.</p>
    </div>

    {{-- New conversation --}}
    <div class="rounded-card border border-line bg-surface p-5 shadow-card">
        <h2 class="font-display text-lg font-semibold text-ink">Nova conversa</h2>

        <form id="flowspec-new-chat-form" class="mt-4 flex flex-col gap-4">
            @csrf

            <x-forms.field label="O que o pipeline deve fazer?" for="flowspec-new-message" name="message" required
                hint="Cite os sistemas pelo nome (ex.: SVL, IAM) — a documentação deles entra automaticamente no contexto.">
                <x-forms.textarea id="flowspec-new-message" name="message" rows="4"
                    placeholder="Ex.: com base na documentação do SVL e do IAM, crie um flowSpec que receba o colaborador, gerencie cache de token JWT por 30 min e faça POST no SVL." />
            </x-forms.field>

            <x-forms.field label="flowSpec de referência (opcional)" for="flowspec-new-reference" name="reference_flowspec"
                hint="Cole um flowSpec existente para usar como base — as posições do canvas são descartadas automaticamente.">
                <x-forms.textarea id="flowspec-new-reference" name="reference_flowspec" rows="6"
                    class="font-mono text-xs"
                    placeholder='{"meta": {...}, "flowSpec": {...}}' />
            </x-forms.field>

            <x-forms.field label="Documentação usada" name="solutions"
                hint="Busque e adicione sistemas para priorizar a documentação deles no prompt. Sem seleção, são inferidos do texto.">
                <x-forms.chips name="solutions" :search-url="route('solutions.search')" placeholder="Buscar sistema…" centered />
            </x-forms.field>

            <x-forms.field label="Documentos específicos (opcional)" name="documents"
                hint="Escolha páginas de documentação ou integrações específicas para usar exatamente essas, sem a seleção automática por relevância.">
                <x-forms.chips name="documents" :search-url="route('flowspec.documents.search')" placeholder="Buscar página ou integração…" centered />
            </x-forms.field>

            <div>
                <x-forms.button type="button" data-ak-ajax="flowspec-new-chat-form" data-ak-action="{{ route('flowspec.store') }}">
                    Gerar flowSpec
                </x-forms.button>
            </div>
        </form>
    </div>

    {{-- Previous conversations --}}
    <div class="mt-6">
        <h2 class="font-display text-lg font-semibold text-ink">Conversas</h2>

        @if ($chats->isEmpty())
            <p class="mt-3 text-sm text-muted">Nenhuma conversa ainda — a primeira geração aparece aqui.</p>
        @else
            <ul class="mt-3 flex flex-col gap-2">
                @foreach ($chats as $chat)
                    <li>
                        <a href="{{ route('flowspec.show', $chat) }}"
                           class="flex items-center justify-between gap-4 rounded-card border border-line bg-surface px-4 py-3 no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink">{{ $chat->title }}</span>
                                <span class="mt-0.5 block text-xs text-muted">{{ $chat->messages_count }} {{ $chat->messages_count === 1 ? 'mensagem' : 'mensagens' }} · {{ $chat->updated_at->diffForHumans() }}</span>
                            </span>
                            <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.layout>
