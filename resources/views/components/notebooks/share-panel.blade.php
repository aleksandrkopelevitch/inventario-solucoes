<div id="{{ $domId }}" data-ak-share-panel
    data-share-url="{{ route('notebooks.share', $notebook) }}"
    data-unshare-url="{{ route('notebooks.unshare', $notebook) }}"
    data-secret-code-url="{{ route('notebooks.secret-code', $notebook) }}">

    <h3 class="font-display text-base font-semibold text-ink">Compartilhar caderno</h3>

    @if ($publicUrl)
        <p class="mt-1 text-sm text-muted">
            Qualquer pessoa com este link vê as páginas deste caderno, sem precisar de login.
        </p>

        <div class="mt-3 flex items-center gap-2">
            <x-forms.input
                type="text"
                readonly
                data-ak-share-url-field
                value="{{ $publicUrl }}"
                class="!h-9 flex-1 !text-xs"
                aria-label="Link público do caderno" />
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
            Gere um link público para compartilhar este caderno com pessoas de fora
            (sem login).
        </p>

        <x-forms.button type="button" data-ak-share-generate class="mt-3 !h-9 w-full !text-sm">
            Gerar link público
        </x-forms.button>
    @endif

    {{-- The secret code, in the same panel as the public link and deliberately
         below it: the two are one subject — who can read what of this caderno.
         The link decides who reads the PROSE, the code who reads the protected
         values inside it, and both are only shown to an administrator
         (NotebookPolicy::administer gates the whole dropdown). --}}
    <div class="mt-4 border-t border-line pt-3">
        <h4 class="text-sm font-semibold text-ink">Código de leitura</h4>
        <p class="mt-1 text-xs text-muted">
            Quem tiver este código consegue ver, um por vez, os valores protegidos
            deste caderno — inclusive no link público. Administradores enxergam
            todos sem código.
        </p>

        <div class="mt-2 flex items-center gap-2">
            <x-forms.input
                type="text"
                readonly
                data-ak-secret-code-field
                value="{{ $notebook->secret_code }}"
                class="!h-9 flex-1 !font-mono !text-xs"
                aria-label="Código de leitura do caderno" />
            <x-forms.button type="button" variant="ghost" data-ak-secret-code-copy
                class="!h-9 !w-9 shrink-0 !p-0" aria-label="Copiar código">
                <x-heroicon-o-clipboard-document class="size-5" />
            </x-forms.button>
        </div>

        <button type="button" data-ak-secret-code-rotate
            class="mt-2 text-xs font-medium text-crit hover:underline">
            Gerar novo código
        </button>
    </div>
</div>
