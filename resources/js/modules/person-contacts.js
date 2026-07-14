// Contatos adicionais repetíveis do form de Pessoa (data-ak-contacts) — tipo
// (select) + valor, além do e-mail/telefone únicos do form.
// Linhas novas entram via botão (não Enter, ao contrário de chips.js — aqui
// cada linha tem 2 campos, não um único valor), saem pelo botão de lixeira.
// Pura delegação em `document`, sem `init()` (mesmo espírito de chips.js) —
// funciona tanto na primeira renderização (linhas vindas do Blade) quanto
// depois de reabrir o painel via AJAX.
//
// Markup gerado aqui precisa espelhar visualmente as linhas renderizadas
// pelo Blade (`resources/views/people/form.blade.php`) — que usam
// `<x-forms.select>`/`<x-forms.input>`/`<x-forms.button variant="ghost">`,
// impossíveis de invocar em tempo de execução no cliente — por isso as
// classes Tailwind abaixo são escritas por extenso (mesmo padrão já usado em
// chips.js::chipHtml() para o mesmo problema).

function nextIndex(container) {
    const n = parseInt(container.dataset.akContactsNext || '0', 10)
    container.dataset.akContactsNext = String(n + 1)
    return n
}

function rowHtml(idx) {
    return `<div data-ak-contact-row class="flex items-start gap-1.5">
        <input type="hidden" name="contacts[${idx}][id]" value="">
        <div class="relative w-[104px] shrink-0">
            <select name="contacts[${idx}][type]" class="w-full appearance-none rounded-field border border-line-2 bg-surface h-9 pl-2.5 pr-6 text-xs text-ink transition duration-150 focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]">
                <option value="email">E-mail</option>
                <option value="phone">Telefone</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="other">Outro</option>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-faint">
                <svg class="h-3.5 w-3.5 fill-current" viewBox="0 0 20 20"><path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/></svg>
            </div>
        </div>
        <input type="text" name="contacts[${idx}][value]" placeholder="valor" class="h-9 flex-1 rounded-field border border-line-2 bg-surface px-3 text-xs text-ink placeholder-faint transition duration-150 focus:outline-none focus:border-accent focus:shadow-[0_0_0_3px_var(--color-accent-soft)]">
        <button type="button" data-ak-contact-remove title="Remover contato" class="relative inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-field p-2 text-muted transition-[color,background-color,border-color,box-shadow,transform] duration-150 ease-out hover:bg-raised hover:text-crit active:translate-y-px">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
        </button>
    </div>`
}

document.addEventListener('click', (e) => {
    const addBtn = e.target.closest('[data-ak-contact-add]')
    if (addBtn) {
        const container = addBtn.closest('[data-ak-contacts]')
        const list = container?.querySelector('[data-ak-contacts-list]')
        if (list) list.insertAdjacentHTML('beforeend', rowHtml(nextIndex(container)))
        return
    }

    const removeBtn = e.target.closest('[data-ak-contact-remove]')
    if (removeBtn) {
        removeBtn.closest('[data-ak-contact-row]')?.remove()
    }
})

export function init() {}
