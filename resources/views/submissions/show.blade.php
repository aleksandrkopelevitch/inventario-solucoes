@php
    // tabs.js swaps only these classes; the base radius/size utilities on each
    // trigger are untouched by the state swap (see documentation/edit.blade.php).
    $tabBase = [
        'targetContainerId' => 'submission-tab-panels',
        'activeClasses'     => ['!bg-surface', '!text-ink', '!shadow-sm'],
        'inactiveClasses'   => ['!bg-transparent', '!text-muted', '!shadow-none'],
    ];
    $tabActive = '!h-8 !gap-1.5 !rounded-md !bg-surface !px-3 !text-xs !font-semibold !text-ink !shadow-sm';
    $tabIdle = '!h-8 !gap-1.5 !rounded-md !bg-transparent !px-3 !text-xs !font-semibold !text-muted !shadow-none';
@endphp

<x-layouts.layout :title="$submission->name">
    <div class="mb-4 animate-ak-fade">
        <a href="{{ route('submissions.index') }}" class="group inline-flex items-center gap-1 text-sm text-accent hover:underline">
            <x-heroicon-o-arrow-left class="size-4 transition-transform duration-150 group-hover:-translate-x-0.5" /> Comitê de Arquitetura
        </a>
    </div>

    <div class="animate-ak-rise">
        <x-submissions.detail-header :submission="$submission" />
    </div>

    {{-- Stage strip + tab switcher on one bar: where the submission is, and
         which surface you're looking at. The strip reports, it doesn't gate —
         every tab is reachable at every stage (App\Support\Cati\SubmissionStages). --}}
    <div class="animate-ak-rise mt-4 flex flex-wrap items-center justify-between gap-4 rounded-card border border-line bg-surface px-4 py-3 shadow-card" style="animation-delay: 60ms">
        <div class="min-w-0 flex-1 basis-80">
            <x-submissions.stage-strip :submission="$submission" />
        </div>

        <div class="flex shrink-0 items-center gap-1 rounded-[9px] bg-raised p-1" role="tablist" aria-label="Preparação, documento ou comitê">
            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="true" tabindex="0"
                data-ak-tabs="{{ json_encode([...$tabBase, 'targetId' => 'submission-tab-prep', 'selectedOnInit' => true]) }}"
                class="{{ $tabActive }}">
                <x-heroicon-o-chat-bubble-left-right class="size-4" /> Preparação
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="false" tabindex="-1"
                data-ak-tabs="{{ json_encode([...$tabBase, 'targetId' => 'submission-tab-document']) }}"
                class="{{ $tabIdle }}">
                <x-heroicon-o-document-text class="size-4" /> Documento
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="false" tabindex="-1"
                data-ak-tabs="{{ json_encode([...$tabBase, 'targetId' => 'submission-tab-diagrams']) }}"
                class="{{ $tabIdle }}">
                <x-heroicon-o-share class="size-4" /> Diagramas
            </x-forms.button>
            <x-forms.button type="button" variant="ghost" role="tab" aria-selected="false" tabindex="-1"
                data-ak-tabs="{{ json_encode([...$tabBase, 'targetId' => 'submission-tab-committee']) }}"
                class="{{ $tabIdle }}">
                <x-heroicon-o-clipboard-document-check class="size-4" /> Comitê
            </x-forms.button>
        </div>
    </div>

    {{-- Every panel stays mounted: an updatable slot returned by a mutation on
         one tab must land even while another tab is showing (ajax-slot.js
         swaps by id, and a slot that isn't in the DOM is silently skipped —
         which would leave the hidden tab stale until a reload). --}}
    <div id="submission-tab-panels" class="animate-ak-rise mt-4" style="animation-delay: 90ms">

        {{-- ---------------------------------------------------------------
             Preparação — the interview, and the document taking shape.
             --------------------------------------------------------------- --}}
        {{-- Not `hidden`: tabs.js's selectedOnInit hides every panel and re-opens
             this one synchronously, so starting hidden only buys a flash of empty
             page — and with JS off this is still the sane thing to land on. --}}
        <div id="submission-tab-prep" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="flex min-w-0 flex-col gap-3">
                @if ($isUntouched)
                    {{-- `$isUntouched` is computed in SubmissionController::show() —
                         one of its three conditions is a query, which doesn't
                         belong in a view.

                         Only while genuinely nothing has happened yet — it stops
                         rendering the moment material is attached, a section gets
                         content, or the person actually replies (the seeded
                         opening from SeedSubmissionChatOpening doesn't count —
                         that's the assistant talking, not the user). That's a
                         better "dismiss" than a button: it disappears because the
                         thing it pointed at got done. The × is there anyway for
                         someone who wants it gone without acting on it yet. --}}
                    <div id="submission-onboarding-hint" class="flex items-start gap-3 rounded-card border border-accent-line bg-accent-soft px-4 py-3">
                        <x-heroicon-o-sparkles class="mt-0.5 size-4 shrink-0 text-accent" />
                        <p class="min-w-0 flex-1 text-sm text-ink">
                            Tem um deck ou documento antigo dessa proposta? Solte na caixa abaixo — ou cole o texto direto.
                            O assistente lê, preenche o que der e pergunta só o resto.
                        </p>
                        <x-forms.button type="button" variant="ghost" class="!size-6 !min-h-0 !shrink-0 !p-0" aria-label="Fechar"
                            data-ak-toggle="submission-onboarding-hint" data-ak-toggle-classes="hidden">
                            <x-heroicon-o-x-mark class="size-4" />
                        </x-forms.button>
                    </div>
                @endif

                <div class="flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-card">
                    <div data-ak-cati-chat-scroll class="min-h-[320px] max-h-[calc(100vh-26rem)] overflow-y-auto pr-1">
                        <x-submissions.chat-thread :chat="$chat" />
                    </div>

                    @can('update', $submission)
                        <x-submissions.composer :submission="$submission" :chat="$chat" />
                    @else
                        <p class="text-xs text-muted">Você não tem permissão para editar esta submissão.</p>
                    @endcan
                </div>
            </div>

            <aside class="flex min-w-0 flex-col gap-5 lg:sticky lg:top-4 lg:self-start">
                <x-submissions.progress :submission="$submission" />
                <x-submissions.sources :submission="$submission" />
            </aside>
        </div>

        {{-- ---------------------------------------------------------------
             Documento — the eleven sections, and the text outputs.
             --------------------------------------------------------------- --}}
        <div id="submission-tab-document" class="hidden flex flex-col gap-5">
            <div class="flex flex-wrap items-center gap-2">
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

            <x-submissions.sections :submission="$submission" />
        </div>

        {{-- ---------------------------------------------------------------
             Diagramas — the four drawings the committee's checklist asks for.
             --------------------------------------------------------------- --}}
        <div id="submission-tab-diagrams" class="hidden flex flex-col gap-4">
            <p class="text-sm text-muted">
                O comitê pede o desenho da solução e um C4 com no mínimo C1 e C2.
                AS IS e TO BE são desenhados no mesmo canvas das integrações — o C4 vem
                da ferramenta que você já usa.
            </p>

            <x-submissions.diagrams :submission="$submission" />
        </div>

        {{-- ---------------------------------------------------------------
             Comitê — what will be argued about, and what was decided.
             --------------------------------------------------------------- --}}
        <div id="submission-tab-committee" class="hidden grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="flex min-w-0 flex-col gap-5">
                <x-submissions.checklist :submission="$submission" />
                <x-submissions.pre-review :submission="$submission" />
            </div>

            <aside class="flex min-w-0 flex-col gap-5">
                <x-submissions.deliberation :submission="$submission" />

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
    </div>
</x-layouts.layout>
