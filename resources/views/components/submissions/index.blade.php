<div id="{{ $domId }}" class="flex flex-col gap-2">
    @forelse ($submissions as $submission)
        @php
            $total = count(\App\Enums\SubmissionSectionKey::cases());
        @endphp
        <a href="{{ route('submissions.show', $submission) }}"
           class="group flex items-center gap-4 rounded-card border border-line bg-surface px-4 py-3 no-underline shadow-card transition hover:border-accent-line">
            <span class="size-2 shrink-0 rounded-full {{ $submission->status->dotClass() }}"></span>

            <span class="min-w-0 flex-1">
                <span class="block truncate font-display text-sm font-semibold text-ink">{{ $submission->name }}</span>
                <span class="block truncate text-xs text-muted">
                    {{ $submission->solution?->name ?? 'Sem solução vinculada' }}
                    @if ($submission->requester) · {{ $submission->requester->name }} @endif
                </span>
            </span>

            <span class="hidden shrink-0 text-xs tabular-nums text-muted sm:block">
                {{ $submission->answered_count }}/{{ $total }} seções
            </span>

            @if ($submission->committee_date)
                <span class="hidden shrink-0 text-xs tabular-nums text-muted md:block">
                    {{ $submission->committee_date->format('d/m/Y') }}
                </span>
            @endif

            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $submission->status->badgeClass() }}">
                {{ $submission->status->label() }}
            </span>
        </a>
    @empty
        <x-ui.empty-state
            illustration="docs"
            title="Nenhuma submissão por aqui"
            description="Crie uma submissão para preparar a ida ao Comitê de Arquitetura sem montar slides à mão."
            illustration-class="max-w-[200px]" />
    @endforelse
</div>
