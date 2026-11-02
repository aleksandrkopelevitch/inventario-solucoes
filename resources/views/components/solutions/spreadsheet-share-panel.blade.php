{{-- The spreadsheet's magic link: the same x-ui.public-link a caderno's share
     panel uses, so the two public links are generated, copied and revoked the
     same way. --}}
<div id="{{ $domId }}">
    <h3 class="font-display text-base font-semibold text-ink">Compartilhar planilha</h3>

    <x-ui.public-link class="mt-3"
        :public-url="$publicUrl"
        :share-url="route('solutions.spreadsheet.share')"
        :unshare-url="route('solutions.spreadsheet.unshare')"
        form-id="spreadsheet-public-link"
        field-label="Link público da planilha"
        shared="Qualquer pessoa com este link vê a planilha de soluções, sem precisar de login. Contatos (e-mail e telefone) dos responsáveis não aparecem no link público."
        unshared="Gere um link público para compartilhar a planilha com pessoas de fora (sem login)." />
</div>
