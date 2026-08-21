{{-- The conversation's context: what is attached to it.

     Rendered inside the composer box, above the textarea, because it is what
     the next message will be answered with — not a setting kept somewhere else.

     Updatable slot (`flowspec-context-slot`): every attach/remove and every sent
     message re-renders this whole block, so the list is always server-truth.
     See App\View\Components\Flowspec\ContextPanel. --}}
<div id="{{ $domId }}">
    @if ($attachments->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5 px-3 pt-3">
            @foreach ($attachments as $attachment)
                <span @class([
                    'group/pill inline-flex max-w-full items-center gap-1.5 rounded-full py-1 pl-2.5 pr-1 text-xs font-medium ring-1',
                    'bg-accent-soft text-ink ring-accent-line' => $attachment->kind === App\Enums\FlowspecAttachmentKind::Document,
                    'bg-raised text-body ring-line' => $attachment->kind !== App\Enums\FlowspecAttachmentKind::Document,
                ])>
                    <x-dynamic-component :component="'heroicon-o-' . $attachment->kind->icon()" class="size-3.5 shrink-0 text-muted" />

                    <span class="truncate" title="{{ $attachment->label }}{{ $attachment->extraction_note ? ' — ' . $attachment->extraction_note : '' }}">{{ $attachment->label }}</span>

                    @if ($attachment->extraction_state === App\Enums\ContextExtractionState::Failed)
                        <span class="shrink-0 rounded-full bg-hot-soft px-1.5 text-[10px] font-semibold uppercase tracking-wider text-hot"
                              title="{{ $attachment->extraction_note }}">ilegível</span>
                    @endif

                    @if ($attachment->hasSensitiveFindings())
                        <span class="shrink-0 rounded-full bg-hot-soft px-1.5 text-[10px] font-semibold uppercase tracking-wider text-hot"
                              title="{{ collect($attachment->sensitive_findings)->pluck('type')->implode(', ') }}">credencial?</span>
                    @endif

                    {{-- Removal needs a chat: on the new-chat screen nothing is
                         persisted yet, and its staged pills are rendered by
                         flowspec-chat.js instead of by this loop. --}}
                    @if ($chat)
                        <x-forms.button type="button" variant="ghost"
                            data-ak-fs-detach="{{ route('flowspec.attachments.destroy', [$chat, $attachment]) }}"
                            aria-label="Remover {{ $attachment->label }} do contexto"
                            class="!ml-0 !rounded-full !px-1 !py-0 !text-base !font-normal !leading-none !text-muted hover:!bg-hot-soft hover:!text-hot">&times;</x-forms.button>
                    @endif
                </span>
            @endforeach
        </div>
    @endif
</div>
