<x-layouts.layout :title="$chat->title" :breadcrumbs="[
    ['label' => 'Gerador de flowSpec', 'url' => route('flowspec.index')],
    ['label' => $chat->title],
]">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
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

            <details class="text-sm">
                <summary class="cursor-pointer text-xs font-medium text-muted hover:text-ink">Contexto de documentação (opcional)</summary>
                <div class="mt-2 max-w-md">
                    <x-forms.select name="solutions[]" multiple size="4">
                        @foreach ($solutions as $solution)
                            <option value="{{ $solution->id }}">{{ $solution->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
            </details>

            <div class="flex justify-end">
                <x-forms.button type="button" data-ak-ajax="flowspec-message-form" data-ak-action="{{ route('flowspec.messages.store', $chat) }}">
                    Enviar
                </x-forms.button>
            </div>
        </form>
    </div>
</x-layouts.layout>
