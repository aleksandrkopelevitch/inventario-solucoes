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
    </div>

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
        </aside>
    </div>
</x-layouts.layout>
