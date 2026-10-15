{{-- A caderno's context document, opened from its pill in the assistant's
     composer (`#main-modal`, NotebookContextDocumentController::show over AJAX).

     The header IS the rename: the name sits in a field with the extension
     printed beside it rather than inside it, because the extension is not the
     person's to change (RenameContextDocument). No `type` on the button — it has
     to stay a submit, or Enter in the field silently stops working (AGENTS.md).

     The body depends on what the browser can draw: an image or a PDF is loaded
     from the file URL (the same action without JSON); a text document arrives
     already read, as $text. --}}
@php
    $fileUrl = route('notebooks.context.show', [$notebook, $media->id]);
    $extension = strtolower((string) $media->extension);
@endphp

<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0 flex-1">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Documento de contexto do caderno</p>

        <form id="ctx-rename-form" class="mt-1.5 flex items-center gap-2">
            @csrf
            @method('PATCH')
            <div class="flex min-w-0 flex-1 items-center rounded-field border border-line-2 bg-surface pr-2.5 transition focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--color-accent-soft)]">
                <x-forms.input name="name" :value="pathinfo($media->file_name, PATHINFO_FILENAME)" maxlength="120"
                    aria-label="Nome do documento"
                    class="!h-9 min-w-0 flex-1 !border-0 !bg-transparent !text-sm !font-semibold !shadow-none focus:!shadow-none" />
                @if ($extension !== '')
                    <span class="shrink-0 text-sm text-faint">.{{ $extension }}</span>
                @endif
            </div>
            <x-forms.button data-ak-ajax="ctx-rename-form"
                data-ak-action="{{ route('notebooks.context.update', [$notebook, $media->id]) }}"
                class="shrink-0 !px-3 !py-1.5">
                Renomear
            </x-forms.button>
        </form>

        <p class="mt-1.5 text-xs text-muted">{{ $media->human_readable_size }} · vale para todas as páginas do caderno</p>
    </div>
    <x-forms.button type="button" variant="ghost" data-close class="!p-1 !text-xl !leading-none !text-faint hover:!bg-transparent" aria-label="Fechar">&times;</x-forms.button>
</div>

<div class="px-5 py-4">
    @if ($kind === 'image')
        <img src="{{ $fileUrl }}" alt="{{ $media->file_name }}"
             class="mx-auto max-h-[65vh] max-w-full rounded-field border border-line bg-raised object-contain">
    @elseif ($kind === 'pdf')
        <iframe src="{{ $fileUrl }}" title="{{ $media->file_name }}"
                class="h-[65vh] w-full rounded-field border border-line bg-raised"></iframe>
    @elseif ($kind === 'text')
        @if ($text === '')
            <p class="py-6 text-center text-sm text-muted">Este documento está vazio.</p>
        @else
            <pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded-field border border-line bg-raised p-4 font-mono text-xs leading-relaxed text-body">{{ $text }}</pre>
        @endif
        @if ($truncated)
            <p class="mt-2 text-xs text-muted">Mostrando só o início do documento. Abra o arquivo para ver tudo.</p>
        @endif
    @else
        <p class="py-6 text-center text-sm text-muted">Não há pré-visualização para este tipo de arquivo.</p>
    @endif

    <div class="mt-3 flex justify-end">
        <a href="{{ $fileUrl }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline">
            <x-heroicon-o-arrow-top-right-on-square class="size-3.5" />
            Abrir em nova aba
        </a>
    </div>
</div>
