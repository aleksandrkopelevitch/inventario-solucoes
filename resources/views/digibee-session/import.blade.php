<x-layouts.layout title="Enviar sessão Digibee">
    <div class="mx-auto max-w-xl space-y-6 py-6"
        data-ak-digibee-import="{{ json_encode(['storeUrl' => route('digibee-session.store'), 'digibeeOrigin' => $digibeeOrigin]) }}">

        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            <h1 class="font-display text-base font-semibold text-ink">Enviar sessão da Digibee</h1>
            <p data-ak-digibee-import-status class="mt-1 text-sm text-muted">Aguardando a sessão da Digibee…</p>
            <x-forms.button type="button" variant="ghost" data-ak-digibee-import-close class="mt-3 hidden !px-2.5 !py-1.5 text-xs">
                Fechar esta janela
            </x-forms.button>
        </div>

        <x-digibee.session-card />
    </div>
</x-layouts.layout>
