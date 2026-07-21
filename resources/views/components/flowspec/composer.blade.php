@props([
    'formId',                 // id of the <form> (also the data-ak-ajax target)
    'action',                 // POST url (data-ak-action)
    'messageId',              // id of the message <textarea>
    'referenceId',            // id of the reference-flowSpec <textarea>
    'submitLabel' => 'Enviar',
    'placeholder' => 'Descreva a integração…',
])

@php
    $menuId  = $formId . '-attach-menu';
    $panelId = $formId . '-reference-panel';
@endphp

{{-- ChatGPT/Claude-style composer: a single rounded box holding the attachment
     pills, the message textarea and a toolbar. "Anexos" reuse the existing
     context plumbing (no file upload): the 📎 menu opens the systems / documents
     chips overlays and the reference-flowSpec modal; selections render as pills
     above the textarea. Behavior lives in flowspec-chat.js (data-ak-fs-*). --}}
<form id="{{ $formId }}"
      class="rounded-2xl border border-line-2 bg-surface shadow-card transition focus-within:border-accent-line focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]">
    @csrf

    {{-- Attachment pills. NEVER display:none this wrapper — it contains the
         chips' position:fixed search overlays, and a display:none ancestor
         hides fixed descendants too (the overlay would never open from the
         empty state). Instead flowspec-chat.js collapses its padding/gap to
         zero when empty, so an empty composer shows no band while the overlays
         stay renderable. --}}
    {{-- Pills flow in a single horizontal wrapping row (chips roots forced to
         w-auto so systems + documents + reference sit side by side, not stacked). --}}
    <div data-ak-fs-pills class="flex flex-wrap items-center px-3">
        <x-forms.chips name="solutions" :search-url="route('solutions.search')"
            placeholder="Buscar sistema…" centered trigger-hidden class="!w-auto" />
        <x-forms.chips name="documents" :search-url="route('flowspec.documents.search')"
            placeholder="Buscar página ou integração…" centered trigger-hidden class="!w-auto" />

        <div data-ak-fs-reference-pill class="hidden">
            <span class="inline-flex items-center gap-1 rounded-full bg-accent-soft py-1 pl-2.5 pr-1 text-xs font-semibold text-ink ring-1 ring-accent-line">
                <x-heroicon-o-code-bracket class="size-3.5" />
                flowSpec de referência
                <x-forms.button type="button" variant="ghost" data-ak-fs-reference-clear aria-label="Remover"
                    class="!ml-0.5 !rounded-full !px-1 !py-0 !text-base !font-normal !leading-none !text-muted hover:!bg-accent-line hover:!text-ink">&times;</x-forms.button>
            </span>
        </div>
    </div>

    {{-- Reference-flowSpec lives in a modal (opened from the 📎 menu) so it never
         takes vertical space in the composer. The textarea stays a descendant of
         this <form>, so its value still submits; Modal.close() leaves it intact
         because this dialog has no [data-content] hook for it to wipe. --}}
    <dialog id="{{ $panelId }}" class="fixed inset-0 m-auto w-full max-w-2xl rounded-card border border-line p-0 shadow-2xl backdrop:bg-ink/40">
        <div class="flex flex-col gap-3 p-5">
            <div>
                <h3 class="font-display text-base font-semibold text-ink">flowSpec de referência</h3>
                <p class="mt-0.5 text-xs text-muted">Cole um flowSpec existente para usar como base — as posições do canvas são descartadas automaticamente.</p>
            </div>
            <x-forms.textarea :id="$referenceId" name="reference_flowspec" rows="14" data-ak-fs-reference-input autofocus
                class="font-mono text-xs"
                placeholder='{"meta": {...}, "flowSpec": {...}}' />
            <div class="flex justify-end gap-2">
                <x-forms.button type="button" variant="ghost" data-ak-fs-reference-clear>Limpar</x-forms.button>
                <x-forms.button type="button" data-close>Concluir</x-forms.button>
            </div>
        </div>
    </dialog>

    {{-- Message input --}}
    <x-forms.textarea :id="$messageId" name="message" rows="2" data-ak-fs-input :placeholder="$placeholder"
        class="max-h-52 !resize-none !rounded-none !border-0 !bg-transparent !px-4 !py-3 !shadow-none focus:!border-0 focus:!shadow-none" />

    {{-- Toolbar --}}
    <div class="flex items-center justify-between gap-2 px-3 pb-3">
        <div class="relative">
            <x-forms.button type="button" variant="ghost" class="!p-2" title="Anexar contexto"
                data-ak-toggle="{{ $menuId }}" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true">
                <x-heroicon-o-paper-clip class="size-5" />
            </x-forms.button>

            <div id="{{ $menuId }}" data-ak-fs-menu
                 class="absolute bottom-full left-0 z-20 mb-2 hidden w-64 rounded-card border border-line bg-surface p-1.5 shadow-lg">
                <x-forms.button type="button" variant="ghost" data-ak-fs-open="solutions"
                    class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                    <x-heroicon-o-cube class="size-4 text-muted" /> Sistema (documentação)
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-ak-fs-open="documents"
                    class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                    <x-heroicon-o-document-text class="size-4 text-muted" /> Documento específico
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-ak-fs-toggle-reference="{{ $panelId }}"
                    class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                    <x-heroicon-o-code-bracket class="size-4 text-muted" /> flowSpec de referência
                </x-forms.button>
            </div>
        </div>

        <x-forms.button type="button" data-ak-fs-send data-ak-ajax="{{ $formId }}" data-ak-action="{{ $action }}">
            {{ $submitLabel }}
        </x-forms.button>
    </div>
</form>
