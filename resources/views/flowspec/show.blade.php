<x-layouts.layout :title="$chat->title" :breadcrumbs="[
    ['label' => 'Gerador de flowSpec', 'url' => route('flowspec.index')],
    ['label' => $chat->title],
]">
    <div class="mx-auto flex w-full max-w-6xl flex-col items-start gap-6 lg:flex-row">
        <div class="flex w-full min-w-0 max-w-3xl flex-1 flex-col gap-6">
            <div>
                <h1 class="font-display text-2xl font-semibold leading-tight text-ink">{{ $chat->title }}</h1>
                @if ($chat->integration)
                    <p class="mt-1 text-xs text-muted">Vinculado à integração <span class="font-medium text-ink">{{ $chat->integration->name }}</span></p>
                @endif
            </div>

            <x-flowspec.thread :chat="$chat" />

            {{-- Composer --}}
            <form id="flowspec-message-form" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)]">
                @csrf

                <x-forms.field name="message">
                    <x-forms.textarea id="flowspec-message-input" name="message" rows="3"
                        placeholder="Peça um ajuste no flowSpec ou descreva a próxima geração…" />
                </x-forms.field>

                <div class="flex justify-end">
                    <x-forms.button type="button" data-ak-ajax="flowspec-message-form" data-ak-action="{{ route('flowspec.messages.store', $chat) }}">
                        Enviar
                    </x-forms.button>
                </div>
            </form>
        </div>

        {{-- Documentação usada: fica fora do <form> (posicionada na lateral), mas
             o `form="flowspec-message-form"` nos inputs do x-forms.chips associa
             os chips ao composer mesmo assim — ver forms/chips.blade.php. --}}
        <aside class="w-full shrink-0 lg:w-80">
            <div class="rounded-card border border-line bg-surface p-4 shadow-[0_1px_3px_rgba(20,58,34,0.04)] lg:sticky lg:top-6">
                <h2 class="text-sm font-semibold text-ink">Documentação usada</h2>
                <p class="mt-1 text-xs text-muted">Adicione sistemas para priorizar a documentação deles no prompt da próxima mensagem. Sem seleção, são inferidos do texto do pedido.</p>
                <x-forms.chips name="solutions" form="flowspec-message-form" :search-url="route('solutions.search')"
                    placeholder="Buscar sistema…" class="mt-3" />

                <h2 class="mt-4 text-sm font-semibold text-ink">Documentos específicos</h2>
                <p class="mt-1 text-xs text-muted">Opcional — escolha páginas ou integrações específicas para usar exatamente essas, sem a seleção automática por relevância.</p>
                <x-forms.chips name="documents" form="flowspec-message-form" :search-url="route('flowspec.documents.search')"
                    placeholder="Buscar página ou integração…" class="mt-3" />
            </div>
        </aside>
    </div>
</x-layouts.layout>
