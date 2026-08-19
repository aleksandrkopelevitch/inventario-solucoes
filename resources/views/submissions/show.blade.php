<x-layouts.layout :title="$submission->name">
    <div class="mb-6 animate-ak-fade">
        <a href="{{ route('submissions.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Comitê de Arquitetura
        </a>
    </div>

    <div class="animate-ak-rise">
        <x-submissions.detail-header :submission="$submission" />
    </div>

    <div class="animate-ak-rise mt-5 flex flex-wrap gap-2" style="animation-delay: 60ms">
        <x-forms.button type="button" variant="glass" onclick="window.location='{{ route('submissions.export.ticket', $submission) }}'">
            <x-heroicon-o-clipboard-document class="size-4" /> Texto do chamado
        </x-forms.button>
        <x-forms.button type="button" variant="glass" onclick="window.location='{{ route('submissions.export.markdown', $submission) }}'">
            <x-heroicon-o-document-arrow-down class="size-4" /> Documento (Markdown)
        </x-forms.button>
        <x-forms.button type="button" onclick="window.location='{{ route('submissions.export.deck', $submission) }}'">
            <x-heroicon-o-presentation-chart-bar class="size-4" /> Baixar deck
        </x-forms.button>
        @can('update', $submission)
            {{-- The deck reads `slide_content` when it is fresh, so this is
                 optional: skip it and the deck prints the full sections. --}}
            <form id="condense-form">@csrf</form>
            <x-forms.button form="condense-form" variant="glass"
                data-ak-ajax="condense-form"
                data-ak-action="{{ route('submissions.slides.condense', $submission) }}">
                <x-heroicon-o-sparkles class="size-4" /> Resumir para slides
            </x-forms.button>
        @endcan
    </div>

    @if ($submission->decided_at)
        {{-- What the committee decided, at the top: from here on it is the
             record of a decision, not a proposal being prepared. --}}
        <div class="animate-ak-rise mt-5 rounded-card border border-line bg-surface p-5 shadow-card" style="animation-delay: 70ms">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display text-sm font-bold text-ink">Deliberação</h2>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $submission->status->badgeClass() }}">
                    {{ $submission->status->label() }}
                </span>
                <span class="text-xs text-muted">
                    {{ $submission->decided_at->format('d/m/Y') }}
                    @if ($submission->decidedBy) · {{ $submission->decidedBy->name }} @endif
                </span>
                @if ($submission->promoted_at)
                    <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-accent">
                        <x-heroicon-o-check-circle class="size-4" /> Publicada na documentação da solução
                    </span>
                @endif
            </div>

            <div class="mt-2"><x-ui.markdown :text="$submission->decision" /></div>

            @if ($submission->conditions)
                <ul class="mt-3 flex flex-col gap-1 border-t border-line pt-3 pl-0">
                    @foreach ($submission->conditions as $condition)
                        <li class="flex items-start gap-2 text-sm text-ink">
                            <x-heroicon-o-flag class="mt-0.5 size-4 shrink-0 text-cat-amber" />
                            {{ $condition['text'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="animate-ak-rise mt-5 grid gap-5 lg:grid-cols-[1fr_360px]" style="animation-delay: 90ms">
        {{-- Left: the submission itself. --}}
        <div class="flex min-w-0 flex-col gap-5">
            <x-submissions.sections :submission="$submission" />
        </div>

        {{-- Right: what we know, what's missing, the material, and the interview.
             Each is its own slot — applying a draft re-renders the sections and
             the checklist, but must not throw away what's typed in the composer. --}}
        <aside class="flex min-w-0 flex-col gap-5">
            <x-submissions.checklist :submission="$submission" />
            <x-submissions.sources :submission="$submission" />

            <div class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-card">
                <div>
                    <h2 class="font-display text-sm font-bold text-ink">Preparação</h2>
                    <p class="mt-0.5 text-xs text-muted">Responda em texto corrido — eu preencho as seções.</p>
                </div>

                <div data-ak-cati-chat-scroll class="max-h-[420px] overflow-y-auto">
                    <x-submissions.chat-thread :chat="$chat" />
                </div>

                @can('update', $submission)
                    <form id="cati-chat-form" class="flex flex-col gap-2">
                        @csrf
                        <x-forms.textarea data-ak-cati-chat-input name="message" rows="2"
                            placeholder="Ex.: roda numa VM na Google Cloud, com VPN para a central." />
                        <x-forms.button type="button" class="self-end"
                            data-ak-cati-chat-send
                            data-action="{{ route('submissions.chat.messages.store', $submission) }}">
                            Enviar
                        </x-forms.button>
                    </form>
                @endcan
            </div>
            <x-submissions.pre-review :submission="$submission" />

            @can('update', $submission)
                <div class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-card">
                    <div>
                        <h2 class="font-display text-sm font-bold text-ink">Registrar deliberação</h2>
                        <p class="mt-0.5 text-xs text-muted">
                            Aprovar publica as seções na documentação da solução — é o que impede o catálogo de envelhecer.
                        </p>
                    </div>

                    <form id="decision-form" class="flex flex-col gap-3">
                        @csrf

                        <x-forms.field label="Resultado" for="decision-status">
                            <x-forms.select id="decision-status" name="status">
                                @foreach ([App\Enums\SubmissionStatus::Approved, App\Enums\SubmissionStatus::ApprovedWithConditions, App\Enums\SubmissionStatus::Rejected] as $option)
                                    <option value="{{ $option->value }}" @selected($submission->status === $option)>{{ $option->label() }}</option>
                                @endforeach
                            </x-forms.select>
                        </x-forms.field>

                        <x-forms.field label="O que foi decidido" for="decision-text" hint="Aceita Markdown.">
                            <x-forms.textarea id="decision-text" name="decision" rows="3">{{ $submission->decision }}</x-forms.textarea>
                        </x-forms.field>

                        <x-forms.field label="Ressalvas" for="decision-conditions" hint="Uma por linha — ficam rastreáveis depois da reunião.">
                            <x-forms.textarea id="decision-conditions" name="conditions_text" rows="2">{{ collect($submission->conditions ?? [])->pluck('text')->implode(PHP_EOL) }}</x-forms.textarea>
                        </x-forms.field>
                    </form>

                    <x-forms.button form="decision-form" class="self-start"
                        data-ak-ajax="decision-form"
                        data-ak-action="{{ route('submissions.decision.store', $submission) }}">
                        Registrar
                    </x-forms.button>
                </div>
            @endcan
        </aside>
    </div>
</x-layouts.layout>
