{{-- The conversation's context: what is attached, and what it costs.

     Rendered inside the composer box, above the textarea — the two things a
     person weighs together ("is the IAM doc in there, and can I still add the
     contract?") should not be two places on the screen.

     Updatable slot (`flowspec-context-slot`): every attach/remove and every
     sent message re-renders this whole block, so the list and the meter can
     never disagree. See App\View\Components\Flowspec\ContextPanel. --}}
<div id="{{ $domId }}" data-ak-fs-context="{{ json_encode(['attachable' => $usage->attachableTokens()]) }}">
    @if ($attachments->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5 px-3 pt-3">
            @foreach ($attachments as $attachment)
                {{-- A <div>, not a <span>: x-ui.inline-edit's editable form has a
                     <div> root, and flow content inside phrasing content is
                     invalid. `inline-flex` makes the box identical either way. --}}
                <div @class([
                    'group/pill inline-flex max-w-full items-center gap-1.5 rounded-full py-1 pl-2.5 pr-1 text-xs font-medium ring-1',
                    'bg-accent-soft text-ink ring-accent-line' => $attachment->kind === App\Enums\FlowspecAttachmentKind::Document,
                    'bg-raised text-body ring-line' => $attachment->kind !== App\Enums\FlowspecAttachmentKind::Document,
                ])>
                    <x-dynamic-component :component="'heroicon-o-' . $attachment->kind->icon()" class="size-3.5 shrink-0 text-muted" />

                    {{-- Renaming is only offered on a persisted chat, like removal
                         below — and only for material the user brought. A
                         `document` attachment is NAMED BY the page it points at,
                         which is read live: letting that name be overwritten here
                         would make the pill disagree with the documentation it
                         stands for. --}}
                    @if ($chat && $attachment->kind !== App\Enums\FlowspecAttachmentKind::Document)
                        <x-ui.inline-edit
                            :action="route('flowspec.attachments.update', [$chat, $attachment])"
                            name="label"
                            :value="$attachment->label"
                            label="Nome do anexo"
                            edit-class="min-w-40 max-w-xs"
                            input-class="!text-xs"
                            class="min-w-0">
                            <span class="truncate" title="{{ $attachment->label }}{{ $attachment->extraction_note ? ' — ' . $attachment->extraction_note : '' }}">{{ $attachment->label }}</span>
                        </x-ui.inline-edit>
                    @else
                        <span class="truncate" title="{{ $attachment->label }}{{ $attachment->extraction_note ? ' — ' . $attachment->extraction_note : '' }}">{{ $attachment->label }}</span>
                    @endif

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
                </div>
            @endforeach
        </div>
    @endif

    {{-- The meter. Always rendered, even with nothing attached: the fixed cost
         of the platform rules and the corpus examples is real, and starting the
         bar at zero would make the first attachment look like it cost far more
         than it did. --}}
    <div class="px-3 pt-2">
        <details class="group/meter">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-[11px] text-muted hover:text-ink">
                <span class="relative h-1 w-24 shrink-0 overflow-hidden rounded-full bg-line">
                    <span @class([
                        'absolute inset-y-0 left-0 rounded-full transition-[width]',
                        'bg-hot' => $usage->nearLimit(),
                        'bg-accent' => ! $usage->nearLimit(),
                    ]) style="width: {{ max(2, $usage->percent()) }}%"></span>
                </span>
                <span @class(['font-medium' => $usage->nearLimit(), 'text-hot' => $usage->nearLimit()])>
                    Contexto {{ $usage->percent() }}%
                </span>
                <span class="text-faint">
                    ({{ number_format($usage->total() / 1000, 0, ',', '.') }}k de {{ number_format($usage->limit / 1000, 0, ',', '.') }}k tokens)
                </span>
                @if ($usage->attachmentsFull())
                    <span class="rounded-full bg-hot-soft px-1.5 py-0.5 font-semibold text-hot">cheio</span>
                @endif
                <x-heroicon-o-chevron-down class="size-3 transition group-open/meter:rotate-180" />
            </summary>

            <dl class="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 rounded-field border border-line bg-canvas px-3 py-2 text-[11px]">
                @foreach ($usage->visibleLines() as $label => $tokens)
                    <dt class="text-muted">{{ $label }}</dt>
                    <dd class="text-right font-mono text-body">{{ number_format($tokens, 0, ',', '.') }}</dd>
                @endforeach
                <dt class="border-t border-line pt-1 font-medium text-ink">Total estimado</dt>
                <dd class="border-t border-line pt-1 text-right font-mono font-medium text-ink">{{ number_format($usage->total(), 0, ',', '.') }}</dd>
            </dl>
            <p class="mt-1.5 text-[11px] text-faint">
                Estimativa — o contexto anexado vai em toda mensagem desta conversa. Ao encher, remova algo para anexar mais; o histórico mais antigo é cortado automaticamente.
            </p>
        </details>
    </div>
</div>
