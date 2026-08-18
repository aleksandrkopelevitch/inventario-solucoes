<div id="{{ $domId }}" class="flex flex-col gap-3">
    @foreach ($sections as $row)
        @php
            $key = $row['key'];
            $section = $row['section'];
            $formId = "confirm-section-{$section->id}";
        @endphp

        <article class="group/row rounded-card border border-line bg-surface p-5 shadow-card">
            <header class="mb-2 flex flex-wrap items-center gap-2">
                <h3 class="font-display text-sm font-bold text-ink">{{ $key->label() }}</h3>

                @if ($key->mandatory())
                    <span class="rounded-full bg-raised px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted">Obrigatória</span>
                @elseif ($key->deckOnly())
                    <span class="rounded-full bg-raised px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted">Só no deck</span>
                @endif

                <span class="ml-auto rounded-full px-2.5 py-1 text-xs font-medium {{ $section->state->badgeClass() }}">
                    {{ $section->state->label() }}
                </span>

                @if ($canEdit && $section->state === \App\Enums\SubmissionSectionState::Drafted)
                    <form id="{{ $formId }}">@csrf</form>
                    <x-forms.button form="{{ $formId }}" variant="glass" class="!px-2.5 !py-1 !text-xs"
                        data-ak-ajax="{{ $formId }}"
                        data-ak-action="{{ route('submissions.sections.confirm', [$submission, $section]) }}">
                        <x-heroicon-o-check class="size-3.5" /> Confirmar
                    </x-forms.button>
                @endif
            </header>

            <x-ui.inline-edit
                :action="$canEdit ? route('submissions.sections.update', [$submission, $section]) : null"
                name="content"
                type="textarea"
                :rows="6"
                :label="$key->label()"
                :value="$section->content"
                empty="Não preenchido">
                @if (filled($section->content))
                    <x-ui.markdown :text="$section->content" />
                @endif
            </x-ui.inline-edit>

            @if (blank($section->content))
                <p class="mt-1 text-xs text-faint">{{ $key->question() }}</p>
            @endif
        </article>
    @endforeach
</div>
