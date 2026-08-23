<div id="{{ $domId }}" class="flex flex-col gap-3">
    @foreach ($sections as $row)
        @php
            $key = $row['key'];
            $section = $row['section'];
            $formId = "confirm-section-{$section->id}";
        @endphp

        {{-- Anchored: the progress rail on the "Preparação" tab links straight
             at one section (data-ak-cati-goto-section), and cati-chat.js needs
             a node to scroll to after it switches tabs. --}}
        <article id="submission-section-{{ $key->value }}" class="group/row scroll-mt-4 rounded-card border border-line bg-surface p-5 shadow-card">
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

            {{-- `edit-class` is not optional here. x-ui.inline-edit defaults to
                 `min-w-48 max-w-xs` — a ceiling sized for retyping one datum on
                 a crowded header, which is the wrong shape for a section of a
                 committee document: read mode is full-width Markdown, so
                 editing it in a 320px box is both a jarring reflow and less
                 room than the text needs. Same opt-out the app's other
                 long-form fields already use (people/notes,
                 solutions/detail-header's notes). --}}
            <x-ui.inline-edit
                :action="$canEdit ? route('submissions.sections.update', [$submission, $section]) : null"
                name="content"
                type="textarea"
                :rows="10"
                edit-class="w-full max-w-full"
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

            @if (filled($section->slide_content))
                {{-- The slide-sized version of the same section. Shown, not
                     hidden, because it is what the committee will actually
                     read on the projector — and a bad summary has to be
                     visible to be fixed. --}}
                <details class="mt-3 border-t border-line pt-3">
                    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wider text-muted">
                        Versão para slide
                        @unless ($section->slideContentIsFresh())
                            <span class="ml-1 rounded-full bg-cat-amber-soft px-2 py-0.5 text-[10px] font-medium normal-case tracking-normal text-cat-amber-ink">
                                desatualizada — o deck usa o texto completo
                            </span>
                        @endunless
                    </summary>
                    <div class="mt-2">
                        <x-ui.markdown :text="$section->slide_content" />
                    </div>
                </details>
            @endif
        </article>
    @endforeach
</div>
