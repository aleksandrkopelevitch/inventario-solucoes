{{-- The caderno's context documents, as pills INSIDE the chat composer box.

     The root is `display: contents`, so these pills and the context-PAGE chips
     docs-chat.js renders share ONE wrapping row (see the composer) instead of
     each owning a row of its own. It is still a real element, so it remains a
     valid updatable-slot target (`context-documents-slot`) — ajax-slot.js
     replaces the whole node, and the replacement carries `contents` too.

     Nothing at all is rendered when there is none. The [+] menu is what says a
     document can be attached; an "ainda não há" line would be a permanent empty
     state inside the one box the eye lands on before typing. --}}
<div id="{{ $domId }}" class="contents">
    @foreach ($documents as $media)
        {{-- Neutral tone, ALWAYS: in this row it is what says "material somebody
             brought", against the accent the context-page chips wear. It used to
             turn accent when checked, which was legible while these pills had a
             labelled section of their own and became a second pill kind the
             moment the two rows merged. Checked/withheld is said by the checkbox
             plus the dim, which is one state on one control. --}}
        <div data-ak-ctx-pill
             class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-raised py-1 pl-1.5 pr-1 text-xs font-medium text-body opacity-60 ring-1 ring-line transition-opacity has-checked:text-ink has-checked:opacity-100">
            {{-- Unchecking withholds the document from THIS message without
                 detaching it from the caderno — the ✕ below is what detaches. --}}
            <x-forms.checkbox data-ak-context-doc :value="$media->id" checked class="shrink-0"
                title="Incluir esta mensagem" />
            <x-heroicon-o-paper-clip class="size-3.5 shrink-0 text-muted" />
            <span class="max-w-[9rem] truncate"
                  title="{{ $media->file_name }} · {{ $media->human_readable_size }} · documento do caderno, vale para todas as páginas">
                {{ $media->file_name }}
            </span>

            <form id="ctx-remove-{{ $media->id }}">
                @csrf
                @method('DELETE')
            </form>
            <x-forms.button type="button" variant="ghost"
                class="!size-5 !rounded-full !p-0 shrink-0 !text-muted hover:!bg-crit-soft hover:!text-crit"
                data-ak-ajax="ctx-remove-{{ $media->id }}"
                data-ak-action="{{ route('notebooks.context.destroy', [$notebook, $media->id]) }}"
                data-ak-confirm="Remover este documento de contexto?"
                aria-label="Remover {{ $media->file_name }} do contexto" title="Remover do caderno">
                <x-heroicon-o-x-mark class="size-3" />
            </x-forms.button>
        </div>
    @endforeach
</div>
