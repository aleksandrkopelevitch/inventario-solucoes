{{-- The spreadsheet's magic link. Same markup contract as a caderno's
     (`x-notebooks.share-panel`) — `data-ak-share-panel` plus the share/unshare
     URLs — so `docs-share.js` generates, copies and revokes it unchanged. --}}
<div id="{{ $domId }}" data-ak-share-panel
    data-share-url="{{ route('solutions.spreadsheet.share') }}"
    data-unshare-url="{{ route('solutions.spreadsheet.unshare') }}">

    <h3 class="font-display text-base font-semibold text-ink">Compartilhar planilha</h3>
    <h4 class="mt-3 text-sm font-semibold text-ink">Link público (sem login)</h4>

    @if ($publicUrl)
        <p class="mt-1 text-sm text-muted">
            Qualquer pessoa com este link vê a planilha de soluções, sem precisar de login.
            Contatos (e-mail e telefone) dos responsáveis não aparecem no link público.
        </p>

        <div class="mt-3 flex items-center gap-2">
            <x-forms.input
                type="text"
                readonly
                data-ak-share-url-field
                value="{{ $publicUrl }}"
                class="!h-9 flex-1 !text-xs"
                aria-label="Link público da planilha" />
            <x-forms.button type="button" variant="ghost" data-ak-share-copy
                class="!h-9 !w-9 shrink-0 !p-0" aria-label="Copiar link">
                <x-heroicon-o-clipboard-document class="size-5" />
            </x-forms.button>
        </div>

        <div class="mt-3 flex items-center justify-between gap-2">
            <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
                <x-heroicon-o-arrow-top-right-on-square class="size-4" /> Abrir
            </a>
            <button type="button" data-ak-share-revoke
                class="text-xs font-medium text-crit hover:underline">
                Revogar acesso
            </button>
        </div>
    @else
        <p class="mt-1 text-sm text-muted">
            Gere um link público para compartilhar a planilha com pessoas de fora
            (sem login).
        </p>

        <x-forms.button type="button" data-ak-share-generate class="mt-3 !h-9 w-full !text-sm">
            Gerar link público
        </x-forms.button>
    @endif
</div>
