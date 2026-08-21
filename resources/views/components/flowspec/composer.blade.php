@props([
    'formId',                 // id of the <form> (also the data-ak-ajax target)
    'action',                 // POST url (data-ak-action)
    'messageId',              // id of the message <textarea>
    'chat' => null,           // FlowspecChat|null — null on the new-chat screen
    'submitLabel' => 'Enviar',
    'placeholder' => 'Descreva a integração…',
])

@php
    $menuId = $formId . '-attach-menu';

    // Everything flowspec-chat.js needs to decide between attaching NOW and
    // staging until send. `chatId` null is the whole difference: with no
    // conversation there is nothing to attach to yet, so files, pastes and
    // picked documents ride along in this form instead.
    $config = [
        'chatId'         => $chat?->getKey(),
        'attachUrl'      => $chat ? route('flowspec.attachments.store', $chat) : null,
        'pickerUrl'      => route('flowspec.attachments.picker', $chat ? ['chat' => $chat->getKey()] : []),
        'suggestUrl'     => route('flowspec.documents.suggest', $chat ? ['chat' => $chat->getKey()] : []),
        'pasteThreshold' => (int) config('services.flowspec.paste_threshold_chars'),
    ];
@endphp

{{-- ChatGPT/Claude-style composer: one rounded box holding the conversation's
     context, the message textarea and a toolbar.

     There are exactly TWO ways to add context, and the 📎 menu has exactly two
     items to match: documentation already in the inventory, and material the
     user brings (a file from disk, or a long paste — which becomes a text
     attachment on its own, the way the Claude client does it). The old third
     option, a standalone "flowSpec de referência" editor, is gone: pasting a
     pipeline is recognized as one automatically (AttachFlowspecText).

     Behavior lives in flowspec-chat.js (data-ak-fs-*). --}}
<form id="{{ $formId }}" data-ak-fs-composer="{{ json_encode($config) }}" enctype="multipart/form-data"
      class="rounded-2xl border border-line-2 bg-surface shadow-card transition focus-within:border-accent-line focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]">
    @csrf

    {{-- Persisted context + meter (server-rendered slot). --}}
    <x-flowspec.context-panel :chat="$chat" />

    {{-- Context staged in this composer but not yet persisted — only ever
         populated on the new-chat screen. flowspec-chat.js renders the pills
         here AND keeps the hidden inputs below them, so a normal
         `new FormData(form)` picks the whole selection up with no custom submit
         path (see the DataTransfer note in flowspec-chat.js for the files). --}}
    <div data-ak-fs-pending class="flex flex-wrap items-center gap-1.5 px-3 empty:hidden"></div>

    {{-- Documentation matching what is being typed. Suggestions only: this is
         what replaced the automatic injection, so nothing here is in the
         context until it is clicked. --}}
    <div data-ak-fs-suggestions class="px-3 empty:hidden"></div>

    {{-- Message input --}}
    <x-forms.textarea :id="$messageId" name="message" rows="2" data-ak-fs-input :placeholder="$placeholder"
        class="max-h-52 !resize-none !rounded-none !border-0 !bg-transparent !px-4 !py-3 !shadow-none focus:!border-0 focus:!shadow-none" />

    {{-- The file picker. `multiple`, and named `files[]` so the new-chat POST
         carries staged files natively; on an existing conversation
         flowspec-chat.js uploads on pick and clears it again. --}}
    {{-- `!hidden`, not `hidden`: x-forms.input's own base classes include
         `block`, and which of the two display utilities wins is decided by
         Tailwind's output order, not by the order written here. --}}
    <x-forms.input type="file" name="files[]" multiple data-ak-fs-file-input class="!hidden"
        accept=".pdf,.pptx,.docx,.txt,.md,.csv,.json,.yaml,.yml,.xml,.png,.jpg,.jpeg,.webp,.svg" />

    {{-- Toolbar --}}
    <div class="flex items-center justify-between gap-2 px-3 pb-3">
        <div class="relative">
            <x-forms.button type="button" variant="ghost" class="!p-2" title="Anexar contexto"
                data-ak-toggle="{{ $menuId }}" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true">
                <x-heroicon-o-paper-clip class="size-5" />
            </x-forms.button>

            <div id="{{ $menuId }}" data-ak-fs-menu
                 class="absolute bottom-full left-0 z-20 mb-2 hidden w-72 rounded-card border border-line bg-surface p-1.5 shadow-lg">
                {{-- Opens the generic side panel directly (side-panel.js), so
                     there is no flowSpec-specific code between the click and the
                     panel — flowspec-chat.js only handles what comes back OUT of
                     it (the checked references). --}}
                <x-forms.button type="button" variant="ghost" data-ak-fs-open-picker
                    data-ak-panel-open data-ak-panel-url="{{ $config['pickerUrl'] }}" data-ak-panel-size="medium"
                    class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                    <x-heroicon-o-document-text class="size-4 text-muted" />
                    <span class="flex flex-col items-start leading-tight">
                        <span>Documentos do inventário</span>
                        <span class="text-[11px] text-faint">Páginas de soluções e integrações</span>
                    </span>
                </x-forms.button>
                <x-forms.button type="button" variant="ghost" data-ak-fs-open-file
                    class="!w-full !justify-start !px-3 !py-2 !font-normal !text-body">
                    <x-heroicon-o-arrow-up-tray class="size-4 text-muted" />
                    <span class="flex flex-col items-start leading-tight">
                        <span>Arquivo do computador</span>
                        <span class="text-[11px] text-faint">PDF, deck, texto, planilha ou imagem</span>
                    </span>
                </x-forms.button>
                <p class="px-3 pb-1 pt-1.5 text-[11px] leading-snug text-faint">
                    Texto longo colado na caixa vira anexo automaticamente.
                </p>
            </div>
        </div>

        <x-forms.button data-ak-fs-send data-ak-ajax="{{ $formId }}" data-ak-action="{{ $action }}">
            {{ $submitLabel }}
        </x-forms.button>
    </div>
</form>
